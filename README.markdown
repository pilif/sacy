Smarty Asset Compiler (sacy)
============================

It's a well-known practice that every request for an asset on your HTML page increases the loading time, as even with unlimited bandwidth, there's increased latency for making the request, waiting for the server to process it and to send the data back.

So by now it has become good practice to, in production mode, send out all assets (assets being JavaScript and CSS in the course of this description) together in one big file.

Various approaches have been used for this so far, the most common ones are these:

* Using some sort of Makefile or similar process, collect the assets, combine them and then link them. This works very nicely, but the additional deployment step is easily forgotten and even if you recompile the assets, old versions of the compiled file could (and should!) be stored in the clients cache.
* Serving all assets together using a specially created gateway script. This allows you to forego the compilation process, but you have to be very careful not to break client side caching when you use any server side scripting language.

Both solutions also require you to keep them in mind during development: The first one requires you to check for the existence of the compiled file and serve that or the individual files while the second one forces you to do some kind of registry to notify the central asset serving script about the whereabouts of the file.

Smarty Asset Compiler (*sacy*) is (as the name suggests) a Plugin for the widely used PHP templating engine [Smarty](http://www.smarty.net) that provides a fresh approach that solves (nearly) all problems with the traditional solutions.

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

and let's further assume that these CSS files exists and you want to have them compiled into one, if possible.

If so, place the sacy plugin file in your Smarty plugin folder and change your HTML to look like this:

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

At request time, sacy will parse the content of the block, extract the CSS links and script tags sourcing files from the same server, check whether it has already cached the compiled version. If not, it'll create one directly on the file system. Note that this process can take a long time for JavaScript files as all sourced files are Minified before being stored.

At any rate, it'll remove the old `<link>`- and `<script>`-tags and add new ones, so that your HTML will look like this:

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

sacy now takes into account the media attribute for CSS files and only groups links together if they share the same media attribute. The reason for this is that you probably do not want your print-style intermixed with your screen style.

This also means though, that to achieve the optimum performance, you should group links with the same media attribute together:

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
    
sacy will ignore all referenced resources if the given URL-string contains a scheme and/or a host name. While PHP's built-in support for networking protocols would allow for the handling of remote files, in most of the cases this is **not** what the user expects (think "ad-tracking code"). If you need this feature, you will have to patch sacy accordingly, but keep in mind that checking the last-modified-date of remote resources is very costly and possibly inaccurate.

Also, remote resources can change at will, which would cause the whole cache file to be regenerated way too often.

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
    
Web server configuration hints
------------------------------

Because the name of the generated file changes the moment you change any of the dependent files, this also means that a web browser or even a proxy server will never have to re-request a file once it has been downloaded.

To make this perfectly clear to both browsers and proxy-servers, set these the Expires-header far into the future and set Cache-Control to public so proxies will cache the file too. This will greatly decrease the load on your server and the bandwidth consumed.

To do this in Apache, I have used these directives:

    <Location /assetcache>
          ExpiresActive On
          ExpiresDefault "access plus 1 year"
          Header merge Cache-Control public
    </Location>

I'm sure other browsers will provide similar methods.

Advantages
----------

* **ease of use**: with sacy, you do not have to change your templates to make the caching work. It'll work in the background and do its stuff
* **correct caching**: Your compiled CSS is written to the disk the web server is using, thus you can offload all the work needed for correct client-side caching (ETag, If-Modified-Since, and so on) to the webserver which should already be king at doing just that.
* **efficiency**: The static file will be directly sent over by your webserver. No need to hit PHP or any other processor to render the CSS - heck you could even configure sacy to place the compiled file on a different server used for serving just static content.
* **automatically up-to-date**: Because a new static file is generated whenever your assets change, you'll never have to worry about convincing clients that the files have changed. Even if one CSS-file of the compilation changed, the URL will change completely and will cause the clients to request the new file
* **ease of development**: You can keep all assets in non-minified development form to work with them. You can even disable the plugin and have the assets included the traditional way during development, leaving you with all the methods for debugging you would want.

Features
--------

There are other solutions for this around, but sacy has a few really unique features:

* **Fallback**: If at any time there is an issue in generating the cached copy, sacy will not alter the existing link tags. Sure: More requests will be sent to the server, but nothing will break.
* **Concurrency**: If two requests come in at the same time and the cache-file does not exist, sacy will neither create a corrupted cache file, *nor will it block* any request. Any request being processed while the cache is being written will have the individual links in the code. Any subsequent request will link to the compiled file.
* **Being Helpful**: The unique name of the cache file contains the base names of the CSS files used to create the compilation which helps you to debug this quickly. If you look inside the file, you'll find a list of the full path names (minus the `DOCUMENT_ROOT` as to not expose private data)
* **url()-rewriting**: If you are using relative urls in your css files, they would break if they are used in css-files in hierarchies deeper than what sacy exposes. This would cause background images not to load and lots of other funny mistakes. Thus, sacy looks at the CSS files and tries to rewrite all url()'s it finds using information from `ASSET_COMPILE_URL_ROOT` to point to the correct files again.

Installation
------------

1. Place `block.asset_compile.php` and `sacy/sacy.php` in your smarty plugin directory
2. Edit the two constants at the top. The `OUTPUT_DIR` is where you want the files written to, `URL_ROOT` is how that directoy is accessible from the outside.
3. there is no step 3

I recommend setting `OUTPUT_DIR` to some place that's not publicly accessible and then using symlinks or a webserver level alias directory to make just that directory accessible to the outside. You do not want world-writable directories exposed more than what's absolutely needed.

Known Issues
------------

* sacy doesn't handle HTML comments (bug #4), so even if a tag is inside HTML comments, it will still be rendered. Use Smarty comments for now. This also means that sacy will fail in cases where IE's conditional comments are used to fetch additional files. For now, put these conditional comments outside of a `{asset_compile}` tag
* sacy does not contain a CSS parser. It does rewrite any relative url() it finds inside the CSS-file to absolute ones though, but it does not follow @import directives. This means that while @import will not break, you will lose the compilation feature as `@import`ed files get loaded the traditional way


Acknowledgements
----------------

This is based around the blog entry [How we hash our Javascript for better caching and less breakage on updates](http://blog.greenfelt.net/2009/09/01/caching-javascript-safely/) with some added simplifications and adapted for use with Smarty.

Thanks for that blog entry and the [accompanying discussions](http://news.ycombinator.com/item?id=799994) on Hacker News.

Licence
-------

sacy is © 2009 by Philip Hofstetter <phofstetter@sensational.ch> and is licensed under the MIT License which is reprinted in the LICENSE file accompanying this distribution.

If you find this useful, consider dropping me a line, or, if you want, a patch :-)

