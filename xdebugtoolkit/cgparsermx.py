from mx.TextTools import *
from cgparser import *

class Context:
    def __init__(self):
        self.entries = []
        self._last_entry = None
        self._last_raw_call = None
        self._fl_cache = {}
        self._fn_cache = {}
            
    def set_version(self, taglist, text, l, r, subtags):
        self.version = text[l:r]

    def set_fl(self, taglist, text, l, r, subtags):
        self._last_entry = RawEntry()
        self.entries.append(self._last_entry)

        fl = text[l:r]

        try:
            self._last_entry.fl = self._fl_cache[fl]
        except KeyError:
            self._last_entry.fl = self._fl_cache[fl] = FileName(fl)

    def set_fn(self, taglist, text, l, r, subtags):
        fn = text[l:r]

        try:
            self._last_entry.fn = self._fn_cache[fn]
        except KeyError:
            self._last_entry.fn = self._fn_cache[fn] = FunctionName(fn)
    
    def set_summary(self, taglist, text, l, r, subtags):
        pass
        
    def set_position(self, taglist, text, l, r, subtags):
        self._last_entry.position = int(text[l:r])
        
    def set_time(self, taglist, text, l, r, subtags):
        self._last_entry.self_time = int(text[l:r])

    def set_subcall_cfn(self, taglist, text, l, r, subtags):
        self._last_raw_call = RawCall()
        self._last_entry.add_subcall(self._last_raw_call)

        cfn = text[l:r]

        try:
            self._last_raw_call.cfn = self._fn_cache[cfn]
        except KeyError:
            self._last_raw_call.cfn = self._fn_cache[cfn] = FunctionName(cfn)

    def set_subcall_position(self, taglist, text, l, r, subtags):
        self._last_raw_call.position = int(text[l:r])

    def set_subcall_time(self, taglist, text, l, r, subtags):
        self._last_raw_call.inclusive_time = int(text[l:r])

contextobj = Context()

header_table = (
    # version
    (None, Word, 'version: ', MatchFail),
    (contextobj.set_version, AllNotIn+CallTag, newline, MatchFail),
    (None, AllIn, newline, MatchFail),
    # cmd
    (None, Word, 'cmd: ', MatchFail),
    ('cmd', AllNotIn, newline, MatchFail),
    (None, AllIn, newline, MatchFail),
    # part
    (None, Word, 'part: ', MatchFail),
    ('part', AllNotIn, newline, MatchFail),
    (None, AllIn, newline, MatchFail),
    # events
    (None, Word, 'events: ', MatchFail),
    ('events', AllNotIn, newline, MatchFail),
    (None, AllIn, newline, MatchFail),
)

subcall_table = (
    # cfn
    (None, Word, 'cfn=', MatchFail),
    (contextobj.set_subcall_cfn, AllNotIn + CallTag, newline, MatchFail),
    (None, AllIn, newline, MatchFail),
    # calls
    (None, Word, 'calls=1 0 0', MatchFail),
    (None, AllIn, newline, MatchFail),
    # position
    (contextobj.set_subcall_position, AllIn + CallTag, number, MatchFail),
    (None, Word, ' ', MatchFail),
    # time
    (contextobj.set_subcall_time, AllIn + CallTag, number, MatchFail),
    (None, AllIn, newline, MatchFail),
)

entry_table = (
    # fl
    (None, Word, 'fl=', MatchFail),
    #('fl', AllNotIn, newline, MatchFail),
    #('fl', AllNotIn, newline, MatchFail),
    (contextobj.set_fl, AllNotIn + CallTag, newline, MatchFail),
    (None, AllIn, newline, MatchFail),
    # fn
    (None, Word, 'fn=', MatchFail),
    #('fn', AllNotIn, newline, MatchFail),
    (contextobj.set_fn, AllNotIn + CallTag, newline, MatchFail),
    (None, AllIn, newline, MatchFail),
    # summary
    (None, Word, 'summary: ', +3),
    (contextobj.set_summary, AllNotIn + CallTag, newline, MatchFail),
    (None, AllIn, newline, MatchFail),
    # position
    (contextobj.set_position, AllIn + CallTag, number, MatchFail),
    (None, AllIn, ' ', MatchFail),
    # time
    (contextobj.set_time, AllIn + CallTag, number, MatchFail),
    (None, AllIn, newline, MatchFail),
    # subcalls
    (None, Word + LookAhead, 'cfn=', MatchOk),
    (None, Table, subcall_table, MatchFail, -1),
)

cg_table = (
    # header
    (None, Table, header_table, MatchFail),
    # body
    (None, Word + LookAhead, 'fl=', MatchOk),
    (None, Table, entry_table, MatchFail, -1),
)

if __name__ == '__main__':
    import sys
    import time
    
    contents = open(sys.argv[1]).read()
    timer = time.time()
    result, taglist, nextindex = tag(contents, cg_table, 0)
    if result != 1:
        raise Exception('finished with an error')
    print time.time() - timer
    #print_tags(text,taglist)
