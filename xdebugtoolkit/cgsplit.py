#!/usr/bin/env python

if __name__ == '__main__':
    import sys
    import os.path
    
    from optparse import OptionParser

    parser = OptionParser(usage='./%prog file [file ...]')
    (options, args) = parser.parse_args(sys.argv[1:])
    if len(args) == 0:
        parser.error('Incorrect number of arguments. Use --help option to print usage.')

    separator = '=' * 4 + ' NEW PROFILING FILE ' + '=' * 46 + '\n'

    for file in args:
        basename = os.path.basename(file)
        filename, ext = os.path.splitext(basename)
        
        fh = open(file, 'rU')
        line = fh.readline()
        line = fh.readline()
        
        if line == separator:
            i = 0
            filename_to = filename + '.' + str(i) + ext
            fh_to = open(filename_to, 'w')
            for line in fh:
                if line == separator:
                    fh_to.close()
                    i+=1
                    filename_to = filename + '.' + str(i) + ext
                    fh_to = open(filename_to, 'w')
                else:
                    fh_to.write(line)
        
#        from itertools import takewhile
#        fh = open(file, 'rU')
#        line = fh.readline()
#        line = fh.readline()
#        
#        if line == separator:
#            i = 0
#            while fh.next():
#                filename_to = filename + '.' + str(i) + ext + '_'
#                fh_to = open(filename_to, 'w')
#                block_iter = takewhile(lambda l: l != separator, fh)
#                fh_to.writelines(block_iter)
#                i += 1
