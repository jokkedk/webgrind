'''
Created on May 9, 2009

@author: wicked
'''
import unittest
from cgparser import *


class Test(unittest.TestCase):
    
    #filename = 'fixtures/cachegrind.out.2776'
    filename = 'fixtures/1243043583_646499.cg'
    
    def setUp(self):
        self.parser = XdebugCachegrindFsaParser(self.filename)

    def testHeader(self):
        header = self.parser.get_header()
        self.assertEqual(header.get_version(), '0.9.6')
        self.assertEqual(header.get_part(), '1')
        self.assertEqual(header.get_events(), 'Time')
        self.assertEqual(header.get_cmd(), '/var/www/xdebugtoolkit-trunk/xdebugtoolkit/fixtures/1.php')

    def testBodyTypes(self):
        body = self.parser.get_body()
        self.assertTrue(isinstance(body, RawBody))
        self.assertTrue(isinstance(body.get_header(), RawHeader))
        self.assertTrue(isinstance(body.get_body(), type([])))
        
    def testBody(self):
        body = self.parser.get_body()

    def testToCg(self):
        body = self.parser.get_body()
        generated_content = body.to_cg()
        
        orig_content = open(self.filename, 'r').read()
        self.assertEquals(generated_content, orig_content)


if __name__ == "__main__":
    #import sys;sys.argv = ['', 'Test.testName']
    unittest.main()