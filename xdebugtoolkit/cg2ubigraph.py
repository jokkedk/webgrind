#!/usr/bin/env python

if __name__ == '__main__':
    import sys
    from cgparser import XdebugCachegrindFsaParser
    from reader import CallTree, CallTreeAggregator, XdebugCachegrindTreeBuilder
    from ubigraph import Ubigraph
    from stylers.default import DotNodeStyler
    
    from optparse import OptionParser

    parser = OptionParser(usage='./%prog [options] file [file ...]')
    parser.add_option('-t', '--threshold', dest='threshold', metavar='PERCENT',
                      action="store", type="float", default=1,
                      help='remove fast tails that took less then PERCENT of total execution time. Default is %default%.')
    parser.add_option('-a', '--aggregate', dest='aggregate', metavar='MODE',
                      choices=('func-file', 'none'),
                      action="store", default="func-file",
                      help='aggregation mode. MODE can have values "none" and "func-file". The "none" means that aggregation will be completely off. This is usually very memory wasting, so use it very carefully especially with the xdot. The "func-file" mode means that each call will be keyed by (mapped to) file and function names of every call from it\'s stack. Then all calls will be aggregated (reduced) according to these keys. Default is "%default".')
    (options, args) = parser.parse_args(sys.argv[1:])
    if len(args) == 0:
        parser.error('Incorrect number of arguments. Use --help option to print usage.')
    
    merged_tree = CallTree()
    tree_aggregator = CallTreeAggregator()

    for file in args:
        xdebug_parser = XdebugCachegrindFsaParser(file)
        tree = XdebugCachegrindTreeBuilder(xdebug_parser).get_tree()
        merged_tree.merge(tree)
        if options.aggregate == 'func-file':
            merged_tree = tree_aggregator.aggregate_call_paths(merged_tree)

    merged_tree.filter_inclusive_time(options.threshold)
    Ubigraph().send(merged_tree, DotNodeStyler)
