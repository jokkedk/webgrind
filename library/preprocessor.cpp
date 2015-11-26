/**
 * This is ported from Preprocessor.php for performance.
 */

// #include <Winsock2.h>
#include <arpa/inet.h>
#include <algorithm>
#include <fstream>
#include <map>
#include <queue>
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
    FunctionData(const std::string& _filename, int _line, int _cost) :
        filename(_filename),
        line(_line),
        invocationCount(1),
        summedSelfCost(_cost),
        summedInclusiveCost(_cost)
    {}

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
        std::map<std::string, int> functionNames;
        std::vector<FunctionData> functions;
        std::vector<std::string> headers;

        std::string line;
        std::string buffer;
        int lnr;
        int cost;
        int index;

        // Read information into memory
        while (std::getline(in, line)) {
            if (line.compare(0, 3, "fl=") == 0) {
                // Found invocation of function. Read function name
                std::string function;
                std::getline(in, function);
                function.erase(0, 3);
                getCompressedName(function, false);
                // Special case for ENTRY_POINT - it contains summary header
                if (function == ENTRY_POINT) {
                    std::getline(in, buffer);
                    std::getline(in, buffer);
                    headers.push_back(buffer);
                    std::getline(in, buffer);
                }
                // Cost line
                in >> lnr >> cost;
                std::getline(in, buffer);

                std::map<std::string, int>::const_iterator fnItr = functionNames.find(function);
                if (fnItr == functionNames.end()) {
                    index = nextFuncNr++;
                    functionNames[function] = index;
                    if (std::binary_search(proxyFunctions.begin(), proxyFunctions.end(), function)) {
                        proxyQueue[index];
                    }
                    line.erase(0, 3);
                    getCompressedName(line, true);
                    functions.push_back(FunctionData(line, lnr, cost));
                } else {
                    index = fnItr->second;
                    FunctionData& funcData = functions[index];
                    funcData.invocationCount++;
                    funcData.summedSelfCost += cost;
                    funcData.summedInclusiveCost += cost;
                }
            } else if (line.compare(0, 4, "cfn=") == 0) {
                // Found call to function. ($function/$index should contain function call originates from)
                line.erase(0, 4);
                getCompressedName(line, false); // calledFunctionName
                // Skip call line
                std::getline(in, buffer);
                // Cost line
                in >> lnr >> cost;
                std::getline(in, buffer);

                int calledIndex = functionNames[line];

                // Current function is a proxy -> skip
                std::map< int, std::queue<ProxyData> >::iterator pqItr = proxyQueue.find(index);
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

                functions[index].summedInclusiveCost += cost;

                CallData& calledFromData = functions[calledIndex].getCalledFromData(index, lnr);

                calledFromData.callCount++;
                calledFromData.summedCallCost += cost;

                CallData& subCallData = functions[index].getSubCallData(calledIndex, lnr);

                subCallData.callCount++;
                subCallData.summedCallCost += cost;

            } else if (line.find(": ") != std::string::npos) {
                // Found header
                headers.push_back(line);
            }
        }
        in.close();

        std::vector<std::string> reFunctionNames(functionNames.size());
        for (std::map<std::string, int>::const_iterator fnItr = functionNames.begin();
             fnItr != functionNames.end(); ++fnItr) {
            reFunctionNames[fnItr->second] = fnItr->first;
        }

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
            functionAddresses.push_back(out.tellp());
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

            out << function.filename << '\n' << reFunctionNames[index] << '\n';
        }
        size_t headersPos = out.tellp();
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

    void getCompressedName(std::string& name, bool isFile)
    {
        if (name[0] != '(' || !std::isdigit(name[1])) {
            return;
        }
        int functionIndex = std::atoi(name.c_str() + 1);
        size_t idx = name.find(')');
        if (idx + 2 < name.length()) {
            name.erase(0, idx + 2);
            compressedNames[isFile][functionIndex] = name;
        } else {
            std::map<int, std::string>::iterator nmIt = compressedNames[isFile].find(functionIndex);
            if (nmIt != compressedNames[isFile].end()) {
                name = nmIt->second; // should always exist for valid files
            }
        }
    }

    void writeBuffer(std::ostream& out, std::vector<uint32_t>& buffer)
    {
        for (std::vector<uint32_t>::iterator bItr = buffer.begin(); bItr != buffer.end(); ++bItr) {
            *bItr = toLittleEndian32(*bItr);
        }
        out.write(reinterpret_cast<const char*>(&buffer.front()), sizeof(uint32_t) * buffer.size());
        buffer.clear();
    }

    uint32_t toLittleEndian32(uint32_t value)
    {
        value = htonl(value);
        uint32_t result = 0;
        result |= (value & 0x000000FF) << 24;
        result |= (value & 0x0000FF00) <<  8;
        result |= (value & 0x00FF0000) >>  8;
        result |= (value & 0xFF000000) >> 24;
        return result;
    }

    std::map<int, std::string> compressedNames [2];
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
