// ============================================================================
// gzstream, C++ iostream classes wrapping the zlib compression library.
// Copyright (C) 2001  Deepak Bandyopadhyay, Lutz Kettner
//
// This library is free software; you can redistribute it and/or
// modify it under the terms of the GNU Lesser General Public
// License as published by the Free Software Foundation; either
// version 2.1 of the License, or (at your option) any later version.
//
// This library is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
// Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public
// License along with this library; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
// ============================================================================
//
// File          : gzstream.h
// Revision      : $Revision: 1.5 $
// Revision_date : $Date: 2002/04/26 23:30:15 $
// Author(s)     : Deepak Bandyopadhyay, Lutz Kettner
// 
// Standard streambuf implementation following Nicolai Josuttis, "The 
// Standard C++ Library".
// ============================================================================

#ifndef GZSTREAM_H
#define GZSTREAM_H 1

// standard C++ with new header file names and std:: namespace
#include <iostream>
#include <fstream>
#include <zlib.h>
#include <string>

#ifdef GZSTREAM_NAMESPACE
namespace GZSTREAM_NAMESPACE {
#endif

// ----------------------------------------------------------------------------
// Internal classes to implement gzstream. See below for user classes.
// ----------------------------------------------------------------------------

class gzstreambuf : public std::streambuf {
private:
    static const int bufferSize = 47+256;    // size of data buff
    // totals 512 bytes under g++ for igzstream at the end.

    gzFile           file;               // file handle for compressed file
    char             buffer[bufferSize]; // data buffer
    char             opened;             // open/close state of stream
    int              mode;               // I/O mode

    
    int flush_buffer() {
        // Separate the writing of the buffer from overflow() and
        // sync() operation.
        int w = pptr() - pbase();
        if ( gzwrite( file, pbase(), w) != w)
            return EOF;
        pbump( -w);
        return w;
    }
public:
    gzstreambuf() : opened(0) {
        setp( buffer, buffer + (bufferSize-1));
        setg( buffer + 4,     // beginning of putback area
              buffer + 4,     // read position
              buffer + 4);    // end position      
        // ASSERT: both input & output capabilities will not be used together
    }
    int is_open() { return opened; }

    ~gzstreambuf() { close(); }
    gzstreambuf* open( const char* name, int open_mode) {
        if ( is_open())
            return (gzstreambuf*)0;
        mode = open_mode;
        // no append nor read/write mode
        if ((mode & std::ios::ate) || (mode & std::ios::app)
            || ((mode & std::ios::in) && (mode & std::ios::out)))
            return (gzstreambuf*)0;
        char  fmode[10];
        char* fmodeptr = fmode;
        if ( mode & std::ios::in)
            *fmodeptr++ = 'r';
        else if ( mode & std::ios::out)
            *fmodeptr++ = 'w';
        *fmodeptr++ = 'b';
        *fmodeptr = '\0';
        file = gzopen( name, fmode);
        if (file == 0)
            return (gzstreambuf*)0;
        opened = 1;
        return this;
    }
    
    gzstreambuf * close() {
        if ( is_open()) {
            sync();
            opened = 0;
            if ( gzclose( file) == Z_OK)
                return this;
        }
        return (gzstreambuf*)0;
    }
    
    virtual int underflow() { // used for input buffer only
        if ( gptr() && ( gptr() < egptr()))
            return * reinterpret_cast<unsigned char *>( gptr());
        
        if ( ! (mode & std::ios::in) || ! opened)
            return EOF;
        // Josuttis' implementation of inbuf
        int n_putback = gptr() - eback();
        if ( n_putback > 4)
            n_putback = 4;
        memcpy( buffer + (4 - n_putback), gptr() - n_putback, n_putback);
        
        int num = gzread( file, buffer+4, bufferSize-4);
        if (num <= 0) // ERROR or EOF
            return EOF;
        
        // reset buffer pointers
        setg( buffer + (4 - n_putback),   // beginning of putback area
             buffer + 4,                 // read position
             buffer + 4 + num);          // end of buffer
        
        // return next character
        return * reinterpret_cast<unsigned char *>( gptr());    
    }
    
    virtual int overflow( int c=EOF) { // used for output buffer only
        if ( ! ( mode & std::ios::out) || ! opened)
            return EOF;
        if (c != EOF) {
            *pptr() = c;
            pbump(1);
        }
        if ( flush_buffer() == EOF)
            return EOF;
        return c;
    }
    
    virtual int sync() {
        // Changed to use flush_buffer() instead of overflow( EOF)
        // which caused improper behavior with std::endl and flush(),
        // bug reported by Vincent Ricard.
        if ( pptr() && pptr() > pbase()) {
            if ( flush_buffer() == EOF)
                return -1;
        }
        return 0;
    }    
};

class gzstreambase : virtual public std::ios {
protected:
    gzstreambuf buf;
public:
    
    gzstreambase() { init(&buf); }
    gzstreambase( const char* name, int mode) {
        init( &buf);
        open( name, mode);
    }
    ~gzstreambase() {
        buf.close();
    }
    void open( const char* name, int open_mode) {
        if ( ! buf.open( name, open_mode))
            clear( rdstate() | std::ios::badbit);
    }
    
    void close() {
        if ( buf.is_open())
            if ( ! buf.close())
                clear( rdstate() | std::ios::badbit);
    }
    gzstreambuf* rdbuf() { return &buf; }
};

// ----------------------------------------------------------------------------
// User classes. Use igzstream and ogzstream analogously to ifstream and
// ofstream respectively. They read and write files based on the gz* 
// function interface of the zlib. Files are compatible with gzip compression.
// ----------------------------------------------------------------------------

class igzstream : public gzstreambase, public std::istream {
public:
    igzstream() : std::istream( &buf) {} 
    igzstream( const char* name, int open_mode = std::ios::in)
        : gzstreambase( name, open_mode), std::istream( &buf) {}  
    gzstreambuf* rdbuf() { return gzstreambase::rdbuf(); }
    void open( const char* name, int open_mode = std::ios::in) {
        gzstreambase::open( name, open_mode);
    }
};

class ogzstream : public gzstreambase, public std::ostream {
public:
    ogzstream() : std::ostream( &buf) {}
    ogzstream( const char* name, int mode = std::ios::out)
        : gzstreambase( name, mode), std::ostream( &buf) {}  
    gzstreambuf* rdbuf() { return gzstreambase::rdbuf(); }
    void open( const char* name, int open_mode = std::ios::out) {
        gzstreambase::open( name, open_mode);
    }
};

#ifdef GZSTREAM_NAMESPACE
} // namespace GZSTREAM_NAMESPACE
#endif

#endif // GZSTREAM_H
// ============================================================================
// EOF //
