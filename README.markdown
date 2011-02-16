Smarty Asset Compiler (sacy)
============================

Sacy turns

    {asset_compile}
    <link type="text/css" rel="stylesheet" href="/styles/file1.css" />
    <link type="text/css" rel="stylesheet" href="/styles/file2.css" />
    <link type="text/css" rel="stylesheet" href="/styles/file3.css" />
    <link type="text/css" rel="stylesheet" href="/styles/file4.css" />
    <script type="text/javascript" src="/jslib/file1.js"></script>
    <script type="text/javascript" src="/jslib/file2.js"></script>
    <script type="text/javascript" src="/jslib/file3.js"></script>
    {/asset_compile}

into

    <link type="text/css" rel="stylesheet" href="/assetcache/many-files-1234abc.css" />
    <script type="text/javascript" src="/assetcache/many-files-abc123.js"></script>


Introduction
------------

It's a well-known fact that every request for an asset on your HTML page
increases the loading time, as even with unlimited bandwidth, there's increased
latency for making the request, waiting for the server to process it and to send
the data back.

So by now it has become good practice to, in production mode, send out all
assets (assets being JavaScript and CSS in the course of this description)
together in one big file.

Various approaches have been used for this so far, the most common ones are
these:

* Using some sort of Makefile or similar process, collect the assets, combine
them and then link them. This works very nicely, but the additional deployment
step is easily forgotten and even if you recompile the assets, old versions of
the compiled file could (and should!) be stored in the clients cache.

* Serving all assets together using a specially created gateway script. This
allows you to forego the compilation process, but you have to be very careful
not to break client side caching when you use any server side scripting
language.

  Additionally, by serving assets via your application server, you tie up web
server processes much better used for handling dynamic data.

Both solutions also require you to keep them in mind during development: The
first one requires you to check for the existence of the compiled file and serve
that or the individual files while the second one forces you to do some kind of
registry to notify the central asset serving script about the whereabouts of the
file.

Smarty Asset Compiler (*sacy*) is (as the name suggests) a Plugin for the widely
used PHP templating engine [Smarty](http://www.smarty.net) (sacy works in both
Smarty2 and Smarty3) that provides a fresh approach and solves (nearly) all
problems with the traditional solutions.

Usage
-----

Let's assume that you have an HTML header that looks like this:

    <html>
        <head>
          <title>Title</title>
          <link type="text/css" rel="stylesheet" href="/styles/file1.css" />
          <link type="text/css" rel="stylesheet" href="/styles/file2.css" />
          <link type="text/css" rel="stylesheet" href="/styles/file3.css" />
          <link type="text/css" rel="stylesheet" href="/styles/file4.css" />
          <script type="text/javascript" src="/jslib/file1.js"></script>
          <script type="text/javascript" src="/jslib/file2.js"></script>
          <script type="text/javascript" src="/jslib/file3.js"></script>
        </head>
        <body>
        content
        </body>
    </html>

and let's further assume that these CSS files exists and you want to have them
compiled into one, if possible.

If so, place the sacy plugin file in your Smarty plugin folder and change your
HTML to look like this:

    <html>
        <head>
          <title>Title</title>
          {asset_compile}
              <link type="text/css" rel="stylesheet" href="/styles/file1.css" />
              <link type="text/css" rel="stylesheet" href="/styles/file2.css" />
              <link type="text/css" rel="stylesheet" href="/styles/file3.css" />
              <link type="text/css" rel="stylesheet" href="/styles/file4.css" />
              <script type="text/javascript" src="/jslib/file1.js"></script>
              <script type="text/javascript" src="/jslib/file2.js"></script>
              <script type="text/javascript" src="/jslib/file3.js"></script>
          {/asset_compile}
        </head>
        <body>
        content
        </body>
    </html>

At request time, sacy will parse the content of the block, extract the CSS links
and script tags sourcing files from the same server, and check whether it has
already cached the compiled version.

If not, it'll create one directly on the file system. Note that this process
can take a long time as all sourced files are Minified (using jsMin for
JavaScript and Minify for CSS) before being stored.

At any rate, it'll remove the old `<link>`- and `<script>`-tags and add new
ones, so that your HTML will look like this:

    <html>
        <head>
          <title>Title</title>
          <link type="text/css" rel="stylesheet" href="/assetcache/file1-file2-file3-file4-abc1234def12345.css" />
          <script type="text/javascript" src="/assetcache/file1-file2-file3-deadbeef1234.js"></script>
        </head>
        <body>
        content
        </body>
    </html>

sacy takes into account the media attribute for CSS files and only groups links
together if they share the same media attribute. The reason for this is that
you probably do not want your print-style intermixed with your screen style.

This also means though, that to achieve the optimum performance, you should
group links with the same media attribute together:

    {asset_compile}
        <link type="text/css" media="screen" rel="stylesheet" href="/styles/file1.css" />
        <link type="text/css" media="print" rel="stylesheet" href="/styles/file2.css" />
        <link type="text/css" media="screen" rel="stylesheet" href="/styles/file3.css" />
        <link type="text/css" media="screen" rel="stylesheet" href="/styles/file4.css" />
    {/asset_compile}

will produce

    <link type="text/css" media="screen" rel="stylesheet" href="/csscache/file1-hash.css" />
    <link type="text/css" media="print" rel="stylesheet" href="/csscache/file2-hash.css" />
    <link type="text/css" media="screen" rel="stylesheet" href="/csscache/file2-file3-hash.css" />

whereas a bit of reordering to

    {asset_compile}
        <link type="text/css" media="screen" rel="stylesheet" href="/styles/file1.css" />
        <link type="text/css" media="screen" rel="stylesheet" href="/styles/file3.css" />
        <link type="text/css" media="screen" rel="stylesheet" href="/styles/file4.css" />
        <link type="text/css" media="print" rel="stylesheet" href="/styles/file2.css" />
    {/asset_compile}

will cause only two links to be created:

    <link type="text/css" media="screen" rel="stylesheet" href="/csscache/file1-file3-file4-hash.css" />
    <link type="text/css" media="print" rel="stylesheet" href="/csscache/file2-hash.css" />

Same, by the way, goes for intermixing css and javascript references because
it's possible that javascript code might depend on something that was set in
an earlier CSS file.

So for maximum efficiency, group javascript and CSS file together and don't switch
media types around between two link tags.

sacy will ignore all referenced resources if the given URL-string contains a
scheme and/or a host name.

While PHP's built-in support for networking protocols would allow for the
handling of remote files, in most of the cases this is **not** what the user
expects (think "ad-tracking code"). If you need this feature, you will
have to patch sacy accordingly, but keep in mind that checking the
last-modified-date of remote resources is very costly and possibly
inaccurate.

Also, remote resources can change at will, which would cause the whole cache
file to be regenerated way too often.

Improved CSS Language transformations
-------------------------------------

By now, you will probably have heard of either [less](http://lesscss.org/) or
[sass](http://sass-lang.com/) and you might want to use them for your projects
as they really go a long ways in making your life easier.

Sacy has built-in support to do such transformations if it finds (see below)
[lessphp](http://leafo.net/lessphp) or [PHamlP](http://code.google.com/p/phamlp/)
loaded (sacy is using the PHP ports as to not add dependencies in other
languages, like a working ruby installation plus gems)

Inside an `{asset_compile}` tag, just link to these files like so:

    {asset_compile}
    <link type="text/x-sass" rel="stylesheet" href="/styles/style.sass" />
    <link type="text/x-scss" rel="stylesheet" href="/styles/style.scss" />
    <link type="text/x-less" rel="stylesheet" href="/styles/style.less" />
    {/asset_compile}

Sacy uses the mime-types you provide with the type attribute to invoke the
correct transformer transformer before writing the file to the cache.

See below for some information about dependencies and how to bundle them.

Block parameters
--------------
´{asset_tag}´ supports two parameters:

- `query_strings = ("ignore"|"force-handle")`

  Specifies how sacy should handle relations that contain query-strings
  in their location:

  "ignore" will decline handling tags whose locations contain query strings

  "force-handle" will handle them.

  "ignore" is the default.

- `write_headers = (true|false)`

 Specifies whether sacy should write a header enumerating the source files
 into the compiled files (it will never expose the DOCUMENT_ROOT though).

 This can be helpful for debugging purposes, but one might want to turn
 it off for file size reasons (ridiculous considering the size of the headers)
 or to not expose information.

 true is the default, so headers are written

- `debug_toggle = (<string>|false)`

 If `$_GET[<debug_toggle>]` or `$_COOKIE[<debug_toggle>]` is set to either
 1 or 2, then the following will happen:

 "1" will make sacy decline all processing of a blocks content.

 "2" will make sacy re-process all files as if the cache was empty.

 "3" will make sacy only do transformations (less, sass, scss), but leave
 all other files alone and write one output file per input.
 Use this for development.

 debug_toggle is set to `_sacy_debug` per default. If it's set to `false` sacy
 will never do any debug handling regardless of the request.


Web server configuration hints
------------------------------

Because the name of the generated file changes the moment you change any of the
dependent files, this also means that a web browser or even a proxy server will
never have to re-request a file once it has been downloaded.

To make this perfectly clear to both browsers and proxy-servers, set these the
Expires-header far into the future and set Cache-Control to public so proxies
will cache the file too. This will greatly decrease the load on your server and
the bandwidth consumed.

To do this in Apache, I have used these directives:

    <Location /assetcache>
          ExpiresActive On
          ExpiresDefault "access plus 1 year"
          Header merge Cache-Control public
    </Location>

If you are using nginx, you can accomplish the same thing using

    location /assetcache {
        expires max;
    }

Other browsers will provide similar methods.

Advantages
----------

* **ease of use**: with sacy, you do not have to change your templates to make
the caching work. It'll work in the background and do its stuff
* **correct caching**: Your compiled CSS is written to the disk the web server
is using, thus you can offload all the work needed for correct client-side
caching (ETag, If-Modified-Since, and so on) to the webserver which should
already be king at doing just that.

  Also, it keeps serving assets away from your application server (be it
Apache behind a reverse proxy or FastCGI), saving you lots of RAM.

* **efficiency**: The static file will be directly sent over by your webserver.
No need to hit PHP or any other processor to render the CSS - heck you could
even configure sacy to place the compiled file on a different server used for
serving just static content.

* **automatically up-to-date**: Because a new static file is generated whenever
your assets change, you'll never have to worry about convincing clients that the
files have changed. Even if one CSS-file of the compilation changed, the URL
will change completely and will cause the clients to request the new file

* **ease of development**: You can keep all assets in non-minified development
form to work with them. You can even disable the plugin and have the assets
included the traditional way during development, leaving you with all the
methods for debugging you would want.

Features
--------

There are other solutions for this around, but sacy has a few really unique
features:

* **Transformations**: Sacy can transform less, sass and scss files for you
without making you remember to run deployment scripts or keeping an additional
daemon running that recompiles stuff.

* **Fallback**: If at any time there is an issue in generating the cached copy,
sacy will not alter the existing link tags. Sure: More requests will be sent to
the server, but nothing will break.

* **Concurrency**: If two requests come in at the same time and the cache-file
does not exist, sacy will neither create a corrupted cache file, *nor will it
block* any request. Any request being processed while the cache is being written
will have the individual links in the code. Any subsequent request will link to
the compiled file.

* **Being Helpful**: The unique name of the cache file contains the base names
of the CSS files used to create the compilation which helps you to debug this
quickly. If you look inside the file, you'll find a list of the full path names
(minus the `DOCUMENT_ROOT` as to not expose private data)

* **url()-rewriting**: If you are using relative urls in your css files, they
would break if they are used in css-files in hierarchies deeper than what sacy
exposes. This would cause background images not to load and lots of other funny
mistakes. Thus, sacy looks at the CSS files and tries to rewrite all url()'s it
finds using information from `ASSET_COMPILE_URL_ROOT` to point to the correct
files again. This is now done by Minify which is included in the package.

Installation
------------

Make sure that you define two constants somewhere before {asset_compile}
is evaluated the first time:

`ASSET_COMPILE_OUTPUT_DIR` is the filesystem path where you want the
files written to.

`ASSET_COMPILE_URL_ROOT` is how that directoy is accessible
as an URL relative to the server root.

It's recommended to set `OUTPUT_DIR` to some place that's not publicly accessible
and then using symlinks or a webserver level alias directory to make just that
directory accessible to the outside. You do not want world-writable directories
exposed more than what's absolutely needed.


*PHP 5.3*

If you have PHP 5.3, you can download a single .php file that is actually a
phar archive. This .php file on one end contains all of sacy (minus the
transformation support) and on the other end behaves exactly like a smarty
plugin.

So the installation would be to just download that file and place it in
your smarty plugins directory and you are done.

See the next section how you can create your own bundle that also includes
support for the transformations.


*PHP 5.2*

If you can't use PHP 5.3 yet, you can't make use of the cool phar support,
so you will have to download the full source code and extract the whole
contents of the `src/` folder into your smarty/plugins folder (including
the subfolder).

If you need transformation support, make sure that you load the two packages
before you use sacy so that the transformation support can be enabled.

There is no way to create a bundle containing all needed files.

Building bundles
----------------

Let's say you want to create one single smarty plugin file (for easy
deployment) that contains all dependencies for both minification and
transformation.

If you have PHP 5.3 you can do that using the `build.php` script that
comes with the sacy source code.

build.php is going to write a phar file, so you need to have phar write
support enabled in your php.ini (set `phar.readonly` to off). Then you
can execute the build script from the command line.

It supports the following parameters:

`-c` will add CSSMin to the bundle

`-j` will add JSMin support into the bundle

Note that those two are required by all means for sacy to work. If you
decide not to bundle them, that means that you need to have them available
and loaded somewhere else or sacy will blow up.

Then, the script has two more optional parameters:

`--with-phamlp=<dir>`: pass the path to the extracted source code
archive of PHamlP. This will enable sacy to transform both sass and scss
files.

`--with-lessphp=<dir>`: pass the path to the extracted source code
archive of lessphp. This will enable sacy to transform less files.

As before: If you don't bundle these two but you have them loaded somewhere
before sacy is used, then sacy will use your already loaded copy to do
the thing.

After the script has run, you will find the compiled

`block.asset_compile.php` in the `build/` subdirectory. Place that (yes
just that one file) in your smarty plugins directory and you're done.


Known Issues
------------

* sacy doesn't handle HTML comments (bug #4), so even if a tag is inside HTML
comments, it will still be rendered. Use Smarty comments for now. This also
means that sacy will fail in cases where IE's conditional comments are used to
fetch additional files. For now, put these conditional comments outside of a
`{asset_compile}` tag

* sacy does not contain a CSS parser. It does rewrite any relative url() it
finds inside the CSS-file to absolute ones though, but it does not follow
@import directives. This means that while @import will not break, you will lose
the compilation feature as `@import`ed files get loaded the traditional way


Acknowledgements
----------------

This is based around the blog entry [How we hash our Javascript for better
caching and less breakage on updates](http://blog.greenfelt.net/2009/09/01/caching-javascript-safely/)
with some added simplifications and adapted for use with Smarty.

Thanks for that blog entry and the [accompanying discussions](http://news.ycombinator.com/item?id=799994)
on Hacker News.

Thanks to http://github.com/rgrove/jsmin-php/ and http://code.google.com/p/minify/
for their implementations of JS and CSS minification respectively. Sacy uses a
built-in copy of these if they are not already loaded when sacy is run the
first time.

Licence
-------

sacy is © 2009-2011 by Philip Hofstetter <phofstetter@sensational.ch> and is
licensed under the MIT License which is reprinted in the LICENSE file
accompanying this distribution.

If you find this useful, consider dropping me a line, or,
if you want, a patch :-)

