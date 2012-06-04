class DotNodeStyler:
    
    def __init__(self, max_self_time, total_time, max_call_count, total_call_count):
        # cumulative time characteristics
        self.max_self_time = max_self_time
        self.total_time = total_time
        
        # cumulative call count characteristics
        self.max_call_count = max_call_count
        self.total_call_count = total_call_count
        
    def colorize(self, call):
        r = 0.8
        g = 0.8 - float(call.sum_self_time) / (self.max_self_time) * 0.8
        b = 0.8 - float(call.call_count - 1) / float(self.max_call_count) * 0.8
        return (255 * r, 255 * g ,255 * b)
