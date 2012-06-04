import cgparser

class AggregatedCall(object):
    """
    Aggregated call is a holder for statistic information about multiple
    calls of the same function (written in the same file) same w/o storing
    detailed information about them.
    
    It stores such info:
     * file and function name. This is immutable after creation and the class
       allows to add/merge calls only with the same ones.
     * call_count which is initially = 0;
       add_call() increments the call_count value by 1;
       merge() sums call_count values.
     * min_, max_ and sum_ for self and inclusive times.
    """
    
    __slots__ = ('fn', 'fl', 'subcalls', 'call_count',
                 'min_self_time', 'max_self_time', 'sum_self_time',
                 'min_inclusive_time', 'max_inclusive_time', 'sum_inclusive_time')
    
    def __init__(self, fl, fn):
        self.fn = fn
        self.fl = fl
        self.subcalls = []
        self.call_count = 0
        self.min_self_time = None
        self.max_self_time = None
        self.sum_self_time = 0
        self.min_inclusive_time = None
        self.max_inclusive_time = None
        self.sum_inclusive_time = 0
    
    def add_call(self, fl, fn, self_time, inclusive_time):
        """
        Add a single sibling call (not subcall) to the AggregatedCall.
        Single call means that it's:
         * call_count = 1
         * min_self_time = max_self_time = sum_self_time
         * min_inclusive_time = max_inclusive_time = sum_inclusive_time
        
        It doesn't add any subcalls.
        """
        self._merge(fl, fn, 1, self_time, self_time, self_time, inclusive_time, inclusive_time, inclusive_time)

    def merge(self, call):
        """
        Merge with another aggregated call.
        Aggregated call can have:
         * call_count >= 0
         * different min_, max_ and sum_ _self and _inclusive times
        
        It doesn't merge any subcalls.
        """
        if call.call_count > 0:
            self._merge(call.fl, call.fn, call.call_count, call.min_self_time, call.max_self_time, call.sum_self_time, call.min_inclusive_time, call.max_inclusive_time, call.sum_inclusive_time)
            
    def _merge(self, fl, fn, call_count, min_self_time, max_self_time, sum_self_time,
               min_inclusive_time, max_inclusive_time, sum_inclusive_time):
        """
        Merge routine:
         * requires that 2 calls' have identical fl and fn.
         * sums call_count, sum_self_ and sum_inclusive_ times.
         * minimizes min_self_ and min_inclusive_ times.
         * maximizes min_self_ and max_inclusive_ times.
        
        It doesn't merge any subcalls.
        """
        assert self.fl == fl
        assert self.fn == fn

        self.call_count += call_count
        if self.min_self_time is None:
            self.min_self_time = min_self_time
        else:
            self.min_self_time = min(self.min_self_time, min_self_time)
        self.max_self_time = max(self.max_self_time, max_self_time)
        self.sum_self_time += sum_self_time
        if self.min_inclusive_time is None:
            self.min_inclusive_time = min_inclusive_time
        else:
            self.min_inclusive_time = min(self.min_inclusive_time, min_inclusive_time)
        self.max_inclusive_time = max(self.max_inclusive_time, max_inclusive_time)
        self.sum_inclusive_time += sum_inclusive_time

    def __str__(self):
        return str({
            'fn': self.fn,
            'fl': self.fl,
            'subcalls': self.subcalls,
            'call_count': self.call_count,
            'min_self_time': self.min_self_time,
            'max_self_time': self.max_self_time,
            'sum_self_time': self.sum_self_time,
            'min_inclusive_time': self.min_inclusive_time,
            'max_inclusive_time': self.max_inclusive_time,
            'sum_inclusive_time': self.sum_inclusive_time,
        })


class CallTree:

    def __init__(self):
        self.max_self_time = 0
        self.max_call_count = 0
        self.total_call_count = 0
        self.root_node = AggregatedCall(None, None)
    
    def get_max_self_time(self): return self.max_self_time
    def get_total_time(self): return self.root_node.sum_inclusive_time
    def get_max_call_count(self): return self.max_call_count
    def get_total_call_count(self): return self.total_call_count
    
    def merge(self, tree):
        # update tree statistics
        self.max_call_count = max(self.max_call_count, tree.max_call_count)
        self.max_self_time = max(self.max_self_time, tree.max_self_time)
        self.total_call_count += tree.total_call_count
        
        # merge foreign root node to self root node
        self.root_node.merge(tree.root_node)
        
        # merge foreigh root node's subcalls
        self.root_node.subcalls += tree.root_node.subcalls
    
    def filter_inclusive_time(self, threshold):
        CallTreeFilter().filter_inclusive_time(self, threshold)
    
    def __str__(self):
        return str({
            'max_self_time': self.get_max_self_time(),
            'total_time': self.get_total_time(),
            'max_call_count': self.get_max_call_count(),
            'total_call_count': self.get_total_call_count(),
        })

class XdebugCachegrindTreeBuilder:
    """A tree builder class.
    
    It accepts a parser, uses it to fetch cachegrind's raw structure and
    composes a tree from it.
    """
    
    def __init__(self, parser):
        self.parser = parser

    def get_tree(self):
        body_obj = self.parser.get_body()
        body = body_obj.get_body()

        max_self_time = 0
        stack = []
        stack_pos = []
        root_node = AggregatedCall(None, None)
        stack.append(root_node)
        stack_pos.append(-2) # hack: root node has unknown number of children
        
        for entry in reversed(body):            
            # update tree-wide max_self_time
            max_self_time = max(max_self_time, entry.self_time)

            # create node
            node = AggregatedCall(entry.fl, entry.fn);
            inclusive_time = entry.self_time + sum([x.inclusive_time for x in entry.get_subcalls()])
            node.add_call(entry.fl, entry.fn, entry.self_time, inclusive_time)
            subcalls_count = len(entry.get_subcalls());
            node_pos = subcalls_count - 1
            node.subcalls = [None] * subcalls_count # init subcalls

            # add node to it's parent
            parent = stack[-1]
            if parent == root_node:
                parent.subcalls.insert(0, node)
            else:
                parent.subcalls[stack_pos[-1]] = node
            
            # reduce parent's position
            stack_pos[-1] -= 1

            # fill stacks
            stack.append(node)
            stack_pos.append(node_pos)

            # clean up stack
            while stack_pos and stack_pos[-1] == -1: # position is -1
                del stack[-1], stack_pos[-1]

        # calculate inclusive_time for the root_node separately
        inclusive_time = sum([x.sum_inclusive_time for x in root_node.subcalls])
        root_node.add_call(None, None, 0, inclusive_time)

        # create a tree and fill it with previously calculated data
        tree = CallTree()
        tree.total_call_count = len(body)
        tree.max_call_count = 1
        tree.max_self_time = max_self_time
        tree.root_node = root_node
        
        return tree


class CallTreeFilter:

    def filter_depth(self, tree, depth):
        stack = [tree.root_node]
        stack_pos = [-1, 0]

        while stack:
            stack.append(stack[-1].subcalls[stack_pos[-1]])
            stack_pos[-1] += 1
            stack_pos.append(0)
            
            if len(stack) == depth:
                stack[-1].subcalls = []
                
            # cleanup stack
            while stack and len(stack[-1].subcalls) == stack_pos[-1]:
                del stack[-1], stack_pos[-1]

    def filter_inclusive_time(self, tree, percent_threshold):
        time_threshold = tree.get_total_time() * percent_threshold / 100;
        stack = [tree.root_node]
        stack_pos = [-1, 0]

        while stack:
            parent = stack[-1]
            call = parent.subcalls[stack_pos[-1]]
            if call.sum_inclusive_time >= time_threshold:
                stack.append(call)
                stack_pos[-1] += 1
                stack_pos.append(0)
            else:
                parent.subcalls[stack_pos[-1]:stack_pos[-1]+1] = []
                
            # cleanup stack
            while stack and len(stack[-1].subcalls) == stack_pos[-1]:
                del stack[-1], stack_pos[-1]
        

class CallTreeAggregator:
    
    def __init__(self):
        pass
    
    def aggregate_call_paths(self, tree):
        max_self_time = 0
        max_call_count = 0
        path_map = {}
        
        stack = [tree.root_node]
        stack_pos = [0]
        stack_path = [-1, -1]

        # create a new aggregated call
        new_root_node = AggregatedCall(stack[-1].fl, stack[-1].fn)
        path_map[tuple(stack_path)] = new_root_node

        new_root_node.merge(stack[-1])
        
        while stack:
            stack.append(stack[-1].subcalls[stack_pos[-1]])
            stack_pos[-1] += 1
            stack_pos.append(0)
            
            stack_path.extend((stack[-1].fl, stack[-1].fn))
            
            try:
                call = path_map[tuple(stack_path)]
            except KeyError:
                # create a new aggregated call 
                call = AggregatedCall(stack[-1].fl, stack[-1].fn)
                path_map[tuple(stack_path)] = call
                
                # and append it to it's parent
                parent_call = path_map[tuple(stack_path[:-2])]
                parent_call.subcalls.append(call)

            call.merge(stack[-1])

            # update max_subcall_count and max_self_time
            max_call_count = max(max_call_count, call.call_count)
            max_self_time = max(max_self_time, call.sum_self_time)

            # cleanup stack
            while stack and len(stack[-1].subcalls) == stack_pos[-1]:
                del stack[-1], stack_pos[-1], stack_path[-2:]
                
        new_tree = CallTree()
        new_tree.root_node = new_root_node
        new_tree.max_self_time = max_self_time
        new_tree.total_call_count = tree.total_call_count
        new_tree.max_call_count = max_call_count
        return new_tree
