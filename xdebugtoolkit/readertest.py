'''
Created on May 16, 2009

@author: wicked
'''
import unittest
from cgparser import *
from reader import *


class Test(unittest.TestCase):
    
    #filename = 'fixtures/cachegrind.out.2776'
    filename = 'fixtures/1243043583_646499.cg'
    
    def setUp(self):
        self.parser = XdebugCachegrindFsaParser(self.filename)
        self.tree_builder = XdebugCachegrindTreeBuilder(self.parser)

    def testGetTree(self):
        tree = self.tree_builder.get_tree()
        self.assertEquals(tree.get_max_self_time(), 100122)
        self.assertEquals(tree.get_total_time(), 401402)
        self.assertEquals(tree.get_max_call_count(), 1)
        self.assertEquals(tree.get_total_call_count(), 22)

    def testMergeToEmpty(self):
        empty_tree = CallTree()
        tree = self.tree_builder.get_tree()
        self.assertEquals(empty_tree.get_max_self_time(), 0)
        self.assertEquals(empty_tree.get_total_time(), 0)
        self.assertEquals(empty_tree.get_max_call_count(), 0)
        self.assertEquals(empty_tree.get_total_call_count(), 0)
        empty_tree.merge(tree)
        self.assertEquals(empty_tree.get_max_self_time(), 100122)
        self.assertEquals(empty_tree.get_total_time(), 401402)
        self.assertEquals(empty_tree.get_max_call_count(), 1)
        self.assertEquals(empty_tree.get_total_call_count(), 22)

    def testAggregate(self):
        tree = self.tree_builder.get_tree()
        tree2 = CallTreeAggregator().aggregate_call_paths(tree)
        self.assertEquals(tree2.get_max_self_time(), 200218)
        self.assertEquals(tree2.get_total_time(), 401402)
        self.assertEquals(tree2.get_max_call_count(), 4)
        self.assertEquals(tree2.get_total_call_count(), 22)

if __name__ == "__main__":
    #import sys;sys.argv = ['', 'Test.testName']
    unittest.main()