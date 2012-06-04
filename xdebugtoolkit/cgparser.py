"""
The cgparser package is intended for parsing xdebug's callgrind
files into memory structure. It preserves structure flat, i.e.
it doesn't build any trees, etc. Also it doesn't fix the fact
that xdebug's callgrind files contain only ends (not starts) of
calls, therefore it is supposed to handle this manually.

Currently supported format is limited to such requirements:
 - it supports only non-appended files: xdebug.profiler_append=0 
 - version of the file must be 0.9.6 (at least compatible with
   xdebug 2.0.0..2.0.4, probably with earlier versions too)
   
http://kcachegrind.sourceforge.net/cgi-bin/show.cgi/KcacheGrindCalltreeFormat
"""

import weakref

class CgParseError(Exception):
    pass


class FileName(object):
    """
    Flywight pattern realization for storing file names 
    """
    
    _FileNamePool = weakref.WeakValueDictionary()

    def __new__(cls, value):
        obj = FileName._FileNamePool.get(value)
        if not obj:
            obj = object.__new__(cls)
            FileName._FileNamePool[value] = obj
        return obj

    def __init__(self, value):
        self._value = value

    def __str__(self):
        return self._value


class FunctionName(object):
    """
    Flywight pattern realization for storing function names 
    """
    
    _FunctionNamePool = weakref.WeakValueDictionary()

    def __new__(cls, value):
        obj = FunctionName._FunctionNamePool.get(value)
        if not obj:
            obj = object.__new__(cls)
            FunctionName._FunctionNamePool[value] = obj
        return obj

    def __init__(self, value):
        self._value = value
        self._type = None
        self._clean = value
        if value.startswith('php::'):
            self._type = 'php'
            self._clean = value[5:]
        elif value.startswith('require::'):
            self._type = 'require'
            self._clean = FileName(value[9:])
        elif value.startswith('require_once::'):
            self._type = 'require_once'
            self._clean = FileName(value[14:])
        elif value.startswith('include::'):
            self._type = 'include'
            self._clean = FileName(value[9:])
        elif value.startswith('include_once::'):
            self._type = 'include_once'
            self._clean = FileName(value[14:])

    def __str__(self):
        return self._value

    def get_clean(self):
        return self._clean

class RawHeader:
    
    def __init__(self, version, cmd, part, events):
        self._version = version
        self._cmd = cmd
        self._part = part
        self._events = events
        
    def get_version(self):
        return self._version
    
    def get_cmd(self):
        return self._cmd

    def get_part(self):
        return self._part

    def get_events(self):
        return self._events

    def to_cg(self):
        res = ''
        res += 'version: ' + self._version + "\n"
        res += 'cmd: ' + self._cmd + "\n"
        res += 'part: ' + self._part + "\n"
        res += "\n"
        res += 'events: ' + self._events + "\n"
        res += "\n"
        return res
        

class RawEntry(object):
    """
    The RawEntry class is used for mapping the following entries'
    data from callgrind files:
    - fl=
    - fn=
    - position
    - self time
    - collection of subcalls those are represented by RawCall entries
    """

    __slots__ = ('fn', 'fl', 'self_time', '_subcalls', 'summary', 'position')

    def __init__(self):
        self.fn = None
        self.fl = None
        self.self_time = None
        self._subcalls = []
        self.summary = None
        self.position = None
    
    def add_subcall(self, call):
        self._subcalls.append(call)
    
    def get_subcalls(self):
        return self._subcalls
    
    def to_cg(self):
        res = ''
        res += 'fl=' + str(self.fl) + "\n"
        res += 'fn=' + str(self.fn) + "\n"
        if (str(self.fn) == '{main}'):
            res += "\n"
            res += 'summary: ' + str(self.summary) + "\n"
            res += "\n"
        res += str(self.position) + ' ' + str(self.self_time) + "\n"
        for subcall in self.get_subcalls():
            res += subcall.to_cg()
        res += "\n"
        return res
 

class RawCall(object):
    """
    The RawCall class is used for mapping subcalls in callgrind files
    and handles those data:
    - cfn=
    - calls=
    - call's position
    - call's inclusive time
    """

    __slots__ = ('cfn', 'position', 'inclusive_time')

    def __init__(self):
        self.cfn = None
        self.position = None
        self.inclusive_time = None

    def to_cg(self):
        res = ''
        res += 'cfn=' + str(self.cfn) + "\n"
        res += 'calls=' + '1 0 0' + "\n"
        res += str(self.position) + ' ' + str(self.inclusive_time) + "\n"
        return res

class RawBody:
    
    def __init__(self, header, body):
        self._header = header
        self._body = body
    
    def get_header(self):
        return self._header
    
    def get_body(self):
        return self._body
    
    def to_cg(self):
        res = '';
        res += self._header.to_cg()
        for entry in self._body:
            res += entry.to_cg()
        return res

class XdebugCachegrindFsaParser:
    """
    A low-level FSA based lexer.
    """

    # header states
    # -2 got eof or fl, finish parsing
    # -1 error, finish parsing
    # 0 start
    # 1 got version, expecting cmd
    # 2 got cmd, expecting part
    # 3 gor part, expecting events
    # 4 got events, expecting fl or eof
    header_fsm = {
        #    0   1   2   3   4
        0: [ 1, -1, -1, -1, -1], # version
        1: [-1,  2, -1, -1, -1], # cmd
        2: [-1, -1,  3, -1, -1], # part
        3: [-1, -1, -1,  4, -1], # events
        4: [-1, -1, -1, -1, -2], # fl
        5: [-1, -1, -1, -1, -2], # eof
    }

    # body states:
    # -2 got eof, finish parsing
    # -1 error, finish parsing
    # 0 got header line, expectine more header lines or fl or eof
    # 1 got fl, expecting fn
    # 2 got fn, expecting num or summary
    # 3 got num, expecting fl or cfn or eof
    # 4 got cfn, expecting calls
    # 5 got calls, expecting subcall num
    # 6 got subcall num, expecting fl or cfn or eof
    # 7 got summary, expecting num
    body_fsm = {
        #    0   1   2   3   4   5   6   7
        0: [ 0, -1, -1, -1, -1, -1, -1, -1], # header
        1: [ 1, -1, -1,  1, -1, -1,  1, -1], # fl
        2: [-1,  2, -1, -1, -1, -1, -1, -1], # fn
        3: [-1, -1,  3, -1, -1,  6, -1,  3], # num
        4: [-1, -1, -1,  4, -1, -1,  4, -1], # cfn
        5: [-1, -1, -1, -1,  5, -1, -1, -1], # calls
        6: [-1, -1,  7, -1, -1, -1, -1, -1], # summary
        7: [-2, -1, -1, -2, -1, -1, -2, -1], # eof
    }

    def __init__(self, filename):
        self.fh = file(filename, 'rU')

    def get_header(self):
        self.fh.seek(0)

        state = 0;
        line_no = 0

        while True:
            token = None
            try:
                line = self.fh.next()
                line_no += 1
                if line == '\n':
                    continue
                if line == 'version: 0.9.6\n':
                    token = 0
                if line[0:5] == 'cmd: ':
                    token = 1
                if line == 'part: 1\n':
                    token = 2
                if line == 'events: Time\n':
                    token = 3
                if line[0:3] == 'fl=':
                    token = 4
            except StopIteration:
                token = 5

            try:
                state = self.header_fsm[token][state]
            except:
                state = -1

            if state == -2:
                break

            elif state == -1:
                raise CgParseError(line_no, line, token)

            elif state == 2:
                cmd = line[5:-1]

        return RawHeader('0.9.6', cmd, '1', 'Time')

    def get_body(self):
        body = []

        fl_cache = {}
        fn_cache = {}

        header = self.get_header()

        self.fh.seek(0)

        state = 0;
        line_no = 0

        total_self = 0
        total_calls = 0

        while True:
            token = None
            line = None
            try:
                line = self.fh.next()
                line_no += 1
                if line == '\n':
                    continue
                elif line[0].isdigit():
                    token = 3
                elif line[0:3] == 'fl=':
                    token = 1
                elif line[0:3] == 'fn=':
                    token = 2
                elif line[0:4] == 'cfn=':
                    token = 4
                elif line[0:6] == 'calls=':
                    token = 5
                elif line[0:9] == 'summary: ':
                    token = 6
                elif state == 0:
                    token = 0
            except StopIteration:
                token = 7

            try:
                state = self.body_fsm[token][state]
            except KeyError:
                state = -1

            if state == 1:
                fl = line[3:-1]

                # re-init raw_entry
                raw_entry = RawEntry()
                body.append(raw_entry)

                try:
                    raw_entry.fl = fl_cache[fl]
                except KeyError:
                    raw_entry.fl = fl_cache[fl] = FileName(fl)

            elif state == 2:
                fn = line[3:-1]

                try:
                    raw_entry.fn = fn_cache[fn]
                except KeyError:
                    raw_entry.fn = fn_cache[fn] = FunctionName(fn)

            elif state == 3:
                position, time_taken = map(int, line.split(' '))
                total_self += time_taken
                if fn == '{main}':
                    total_calls += time_taken
                    total_self_before_summary = total_self
                    
                raw_entry.position = position
                raw_entry.self_time = time_taken

            elif state == 4:
                cfn = line[4:-1]

                # init raw_call
                raw_call = RawCall()
                raw_entry.add_subcall(raw_call)

                try:
                    raw_call.cfn = fn_cache[cfn]
                except KeyError:
                    raw_call.cfn = fn_cache[cfn] = FunctionName(cfn)

            elif state == 5:
                calls = line[6:-1]

            elif state == 6:
                position, time_taken = map(int, line.split(' '))
                if fn == '{main}':
                    total_calls += time_taken

                # set raw_call's time and position
                raw_call.position = position
                raw_call.inclusive_time = time_taken

            elif state == 7:
                summary = int(line[9:-1])
                raw_entry.summary = summary

            elif state == -2:
                break

            elif state == -1:
                raise CgParseError(line_no, line, token)

        return RawBody(header, body)
