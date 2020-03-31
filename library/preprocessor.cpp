/**
 * This is ported from Preprocessor.php for performance.
 */

#if !defined(__BYTE_ORDER__) || __BYTE_ORDER__ != __ORDER_LITTLE_ENDIAN__
#ifdef _WIN32
#include <Winsock2.h>
#pragma comment(lib, "ws2_32.lib")
#else
#include <arpa/inet.h>
#endif // _WIN32
#endif // !defined(__BYTE_ORDER__) || __BYTE_ORDER__ != __ORDER_LITTLE_ENDIAN__

#include <algorithm>
#include <cctype>
#include <cstdio>
#include <fstream>
#include <map>
#include <queue>
#include <stdint.h>
#include <sstream>
#include <string>
#include <vector>

/**
 * Fileformat version. Embedded in the output for parsers to use.
 */
#define FILE_FORMAT_VERSION 7

// NR_FORMAT = 'V' - unsigned long 32 bit little endian

/**
 * Size, in bytes, of the above number format
 */
#define NR_SIZE 4

/**
 * String name of main function
 */
#define ENTRY_POINT "{main}"

struct ProxyData
{
    ProxyData(int _calledIndex, int _lnr, int _cost) :
        calledIndex(_calledIndex), lnr(_lnr), cost(_cost)
    {}

    int calledIndex;
    int lnr;
    int cost;
};

struct CallData
{
    CallData(int _functionNr, int _line) :
        functionNr(_functionNr), line(_line), callCount(0), summedCallCost(0)
    {}

    int functionNr;
    int line;
    int callCount;
    int summedCallCost;
};

inline CallData& insertGetOrderedMap(int functionNr, int line, std::map<int, size_t>& keyMap, std::vector<CallData>& data)
{
    int key = functionNr ^ (line << 16) ^ (line >> 16);
    std::map<int, size_t>::iterator kmItr = keyMap.find(key);
    if (kmItr != keyMap.end()) {
        return data[kmItr->second];
    }
    keyMap[key] = data.size();
    data.push_back(CallData(functionNr, line));
    return data.back();
}

struct FunctionData
{
    FunctionData(const std::string& _name, std::string& _filename, int _line, int _cost) :
        name(_name),
        filename(_filename),
        line(_line),
        invocationCount(1),
        summedSelfCost(_cost),
        summedInclusiveCost(_cost)
    {}

    std::string name;
    std::string filename;
    int line;
    int invocationCount;
    int summedSelfCost;
    int summedInclusiveCost;
    std::vector<CallData> calledFromInformation;
    std::vector<CallData> subCallInformation;

    CallData& getCalledFromData(int _functionNr, int _line)
    {
        return insertGetOrderedMap(_functionNr, _line, calledFromMap, calledFromInformation);
    }

    CallData& getSubCallData(int _functionNr, int _line)
    {
        return insertGetOrderedMap(_functionNr, _line, subCallMap, subCallInformation);
    }

private:
    std::map<int, size_t> calledFromMap;
    std::map<int, size_t> subCallMap;
};

class Webgrind_Preprocessor
{
public:

    /**
     * Extract information from inFile and store in preprocessed form in outFile
     *
     * @param inFile Callgrind file to read
     * @param outFile File to write preprocessed data to
     * @param proxyFunctions Functions to skip, treated as proxies
     */
    void parse(const char* inFile, const char* outFile, std::vector<std::string>& proxyFunctions)
    {
        std::ifstream in(inFile);
        std::ofstream out(outFile, std::ios::out | std::ios::binary | std::ios::trunc);

        std::map< int, std::queue<ProxyData> > proxyQueue;
        int nextFuncNr = 0;
        std::vector<FunctionData> functions;
        std::vector<std::string> headers;

        std::string line;
        std::string buffer;
        int lnr;
        int cost;
        int funcIndex;

        // Read information into memory
        while (std::getline(in, line)) {
            if (line.compare(0, 3, "fl=") == 0) {
                // Found invocation of function. Read function name
                std::string function;
                std::getline(in, function);
                function.erase(0, 3);
                int funcCompressedId = getCompressedName(function, false);

                if (function == ENTRY_POINT) {
                    std::getline(in, buffer);
                    if(!buffer.empty() && isdigit(buffer[0])) {
                        // Cost line
                        std::istringstream bufferReader(buffer);
                        bufferReader >> lnr >> cost;
                    } else {
                        // Special case for ENTRY_POINT - it contains summary header
                        std::getline(in, buffer);
                        headers.push_back(buffer);
                        std::getline(in, buffer);
                        // Cost line
                        in >> lnr >> cost;
                        std::getline(in, buffer);
                    }
                } else {
                    // Cost line
                    in >> lnr >> cost;
                    std::getline(in, buffer);
                }

                std::map<int, int>::const_iterator fnItr = funcIndexes.find(funcCompressedId);
                if (fnItr == funcIndexes.end()) {
                    funcIndex = nextFuncNr++;
                    funcIndexes[funcCompressedId] = funcIndex;
                    if (std::binary_search(proxyFunctions.begin(), proxyFunctions.end(), function)) {
                        proxyQueue[funcIndex];
                    }
                    line.erase(0, 3);
                    getCompressedName(line, true);
                    functions.push_back(FunctionData(function, line, lnr, cost));
                } else {
                    funcIndex = fnItr->second;
                    FunctionData& funcData = functions[funcIndex];
                    funcData.invocationCount++;
                    funcData.summedSelfCost += cost;
                    funcData.summedInclusiveCost += cost;
                }
            } else if (line.compare(0, 4, "cfn=") == 0) {
                // Found call to function. ($function/$index should contain function call originates from)
                line.erase(0, 4);
                int funcCompressedId = getCompressedName(line, false); // calledFunctionName
                // Skip call line
                std::getline(in, buffer);
                // Cost line
                in >> lnr >> cost;
                std::getline(in, buffer);

                int calledIndex = funcIndexes[funcCompressedId];

                // Current function is a proxy -> skip
                std::map< int, std::queue<ProxyData> >::iterator pqItr = proxyQueue.find(funcIndex);
                if (pqItr != proxyQueue.end()) {
                    pqItr->second.push(ProxyData(calledIndex, lnr, cost));
                    continue;
                }

                // Called a proxy
                pqItr = proxyQueue.find(calledIndex);
                if (pqItr != proxyQueue.end()) {
                    ProxyData& data = pqItr->second.front();
                    calledIndex = data.calledIndex;
                    lnr = data.lnr;
                    cost = data.cost;
                    pqItr->second.pop();
                }

                functions[funcIndex].summedInclusiveCost += cost;

                CallData& calledFromData = functions[calledIndex].getCalledFromData(funcIndex, lnr);

                calledFromData.callCount++;
                calledFromData.summedCallCost += cost;

                CallData& subCallData = functions[funcIndex].getSubCallData(calledIndex, lnr);

                subCallData.callCount++;
                subCallData.summedCallCost += cost;

            } else if (line.find(": ") != std::string::npos) {
                // Found header
                headers.push_back(line);
            }
        }
        in.close();

        // Write output
        std::vector<uint32_t> writeBuff;
        writeBuff.push_back(FILE_FORMAT_VERSION);
        writeBuff.push_back(0);
        writeBuff.push_back(functions.size());
        writeBuffer(out, writeBuff);
        // Make room for function addresses
        out.seekp(NR_SIZE * functions.size(), std::ios::cur);
        std::vector<uint32_t> functionAddresses;
        for (size_t index = 0; index < functions.size(); ++index) {
            functionAddresses.push_back((uint32_t)out.tellp());
            FunctionData& function = functions[index];
            writeBuff.push_back(function.line);
            writeBuff.push_back(function.summedSelfCost);
            writeBuff.push_back(function.summedInclusiveCost);
            writeBuff.push_back(function.invocationCount);
            writeBuff.push_back(function.calledFromInformation.size());
            writeBuff.push_back(function.subCallInformation.size());
            writeBuffer(out, writeBuff);
            // Write called from information
            for (std::vector<CallData>::const_iterator cfiItr = function.calledFromInformation.begin();
                 cfiItr != function.calledFromInformation.end(); ++cfiItr) {
                const CallData& call = *cfiItr;
                writeBuff.push_back(call.functionNr);
                writeBuff.push_back(call.line);
                writeBuff.push_back(call.callCount);
                writeBuff.push_back(call.summedCallCost);
                writeBuffer(out, writeBuff);
            }
            // Write sub call information
            for (std::vector<CallData>::const_iterator sciItr = function.subCallInformation.begin();
                 sciItr != function.subCallInformation.end(); ++sciItr) {
                const CallData& call = *sciItr;
                writeBuff.push_back(call.functionNr);
                writeBuff.push_back(call.line);
                writeBuff.push_back(call.callCount);
                writeBuff.push_back(call.summedCallCost);
                writeBuffer(out, writeBuff);
            }

            out << function.filename << '\n' << function.name << '\n';
        }
        size_t headersPos = (size_t)out.tellp();
        // Write headers
        for (std::vector<std::string>::const_iterator hItr = headers.begin();
             hItr != headers.end(); ++hItr) {
            out << *hItr << '\n';
        }

        // Write addresses
        out.seekp(NR_SIZE, std::ios::beg);
        writeBuff.push_back(headersPos);
        writeBuffer(out, writeBuff);
        // Skip function count
        out.seekp(NR_SIZE, std::ios::cur);
        // Write function addresses
        writeBuffer(out, functionAddresses);

        out.close();
    }

private:

    int getCompressedName(std::string& name, bool isFile)
    {
        std::map<int, std::string> &names = compressedNames[isFile];
        std::map<std::string, int> &refs = compressedRefs[isFile];

        if (name[0] == '(' && name.length() > 2 && std::isdigit(name[1])) {
            int id = std::atoi(name.c_str() + 1);
            if (names.find(id) == names.end()) {
                size_t idx = name.find(')');
                if (idx + 2 < name.length()) {
                    name.erase(0, idx + 2);
                }

                names[id] = name;
                refs[name] = id;
            }

            std::map<int, std::string>::iterator foundName = names.find(id);
            if (foundName != names.end()) {
                name = foundName->second;
            }
            return id;
        }

        std::map<std::string, int>::iterator foundRef = refs.find(name);
        if (foundRef != refs.end()) {
            return foundRef->second;
        }

        // Not a reference, but let's make one anyway.
        int id = (int)names.size();
        names[id] = name;
        refs[name] = id;
        return id;
    }

    void writeBuffer(std::ostream& out, std::vector<uint32_t>& buffer)
    {
        for (std::vector<uint32_t>::iterator bItr = buffer.begin(); bItr != buffer.end(); ++bItr) {
            *bItr = toLittleEndian32(*bItr);
        }
        out.write(reinterpret_cast<const char*>(&buffer.front()), sizeof(uint32_t) * buffer.size());
        buffer.clear();
    }

    inline uint32_t toLittleEndian32(uint32_t value)
    {
#if defined(__BYTE_ORDER__) && __BYTE_ORDER__ == __ORDER_LITTLE_ENDIAN__
        return value;
#else
        value = htonl(value);
        uint32_t result = 0;
        result |= (value & 0x000000FF) << 24;
        result |= (value & 0x0000FF00) <<  8;
        result |= (value & 0x00FF0000) >>  8;
        result |= (value & 0xFF000000) >> 24;
        return result;
#endif
    }

    std::map<int, std::string> compressedNames [2];
    std::map<std::string, int> compressedRefs [2];

    // Maps a compressed id to a func index.
    std::map<int, int> funcIndexes;
};

int main(int argc, char* argv[])
{
    if (argc < 3) {
        return 1;
    }
    std::vector<std::string> proxyFunctions;
    for (int argIdx = 3; argIdx < argc; ++ argIdx) {
        proxyFunctions.push_back(argv[argIdx]);
    }
    std::sort(proxyFunctions.begin(), proxyFunctions.end());
    Webgrind_Preprocessor processor;
    processor.parse(argv[1], argv[2], proxyFunctions);
    return 0;
}
