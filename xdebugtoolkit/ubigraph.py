import xmlrpclib

class Ubigraph:

    def send(self, tree, node_styler_class):
        node_styler = node_styler_class(tree.get_max_self_time(),
            tree.get_total_time(), tree.get_max_call_count(),
            tree.get_total_call_count())
        
        # Create an object to represent our server. 
        server_url = 'http://127.0.0.1:20738/RPC2'
        server = xmlrpclib.Server(server_url)
        G = server.ubigraph

        G.clear()
        G.set_edge_style_attribute(0, "oriented", "true")
        
        stack = [tree.root_node]
        stack_pos = [-1, 0]
        vertices = [G.new_vertex()]
        
        while len(stack):
            stack.append(stack[-1].subcalls[stack_pos[-1]])
            stack_pos[-1] += 1
            stack_pos.append(0)

            color = "#%02x%02x%02x" % node_styler.colorize(stack[-1])
            # trim fn a bit
            fn = str(stack[-1].fn.get_clean())
            if len(fn) > 30:
                fn = fn[0:12] + '...' + fn[-15:]            
            
            v = G.new_vertex()
            G.set_vertex_attribute(v, 'color', color)
            G.set_vertex_attribute(v, 'label', fn)
            fontsize = str((12 + 10.0 * float(stack[-1].sum_inclusive_time)/float(tree.get_total_time())));
            print fontsize
            G.set_vertex_attribute(v, 'fontsize', fontsize)
            
            e = G.new_edge(vertices[-1], v)
            width = float(stack[-1].sum_inclusive_time)/float(tree.get_total_time())
            G.set_edge_attribute(e, 'width', str(width * 10 + 1))
            #G.set_edge_attribute(e, 'strength', str(width * 9 + 1))            
            
            vertices.append(v)

            # cleanup stack
            while len(stack) and len(stack[-1].subcalls) == stack_pos[-1]:
                del(stack[-1])
                del(stack_pos[-1])
                del(vertices[-1])
