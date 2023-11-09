Webgrind
========
Webgrind is an [Xdebug](http://www.xdebug.org) profiling web frontend in PHP. It implements a subset of the features of [kcachegrind](https://kcachegrind.github.io/) and installs in seconds and works on all platforms. For quick'n'dirty optimizations it does the job. Here's a screenshot showing the output from profiling:

<a href="https://jokkedk.github.io/webgrind/img/webgrind_2008_large.png"><img src="https://jokkedk.github.io/webgrind/img/webgrind_2008_large.png" height="384"></a>

Features
--------
  * Super simple, cross platform installation - obviously :)
  * Track time spent in functions by self cost or inclusive cost. Inclusive cost is time inside function + calls to other functions.
  * See if time is spent in internal or user functions.
  * See where any function was called from and which functions it calls.
  * Generate a call graph using [gprof2dot.py](https://github.com/jrfonseca/gprof2dot)

Suggestions for improvements and new features are more than welcome - this is just a start.

Installation
------------
  1. Download webgrind
  2. Unzip package to favourite path accessible by webserver.
  3. Load webgrind in browser and start profiling by clicking the "update" button in the top right corner

Alternatively, on PHP 5.4+ run the application using the PHP built-in server
with the command `composer serve` or `php -S 0.0.0.0:8080 index.php` if you
are not using Composer.

For faster preprocessing, give write access to the `bin` subdirectory, or compile manually:
  * Linux / Mac OS X: execute `make` in the unzipped folder (requires GCC or Clang.)
  * Windows: execute `nmake -f NMakeFile` in the unzipped folder (requires Visual Studio 2015 or higher.)

See the [Installation Wiki page](https://github.com/jokkedk/webgrind/wiki/Installation) for more.

Use with Docker
---------------

Instead of uploading webgrind to a web server or starting a local one, you can use the [official Docker image](https://hub.docker.com/r/jokkedk/webgrind) to
quickly inspect existing xDebug profiling files. To use the Docker image, run the following command with
`/path/to/xdebug/files` replaced by the actual path of your profiling files.

```
docker run --rm -v /path/to/xdebug/files:/tmp -p 80:80 jokkedk/webgrind:latest
```

Now open `http://localhost` in your browser. After using webgrind you can stop the Docker container by pressing
`CTRL / Strg` + `C`.

To use the built-in file viewer, mount the appropriate files under `/host` in the container.

Credits
-------
Webgrind is written by [Joakim Nygård](http://jokke.dk) and [Jacob Oettinger](http://oettinger.dk). It would not have been possible without the great tool that Xdebug is thanks to [Derick Rethans](http://www.derickrethans.nl).

Current maintainer is [Micah Ng](https://github.com/alpha0010).
