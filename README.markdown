Smarty Asset Compiler (sacy)
============================

It's a well-known practice that every request for an asset on your HTML page increases the loading time, as even with unlimited bandwidth, there's increased latency for making the request, waiting for the server to process it and to send the data back.

So by now it has become good practice to, in production mode, send out all assets (assets being JavaScript and CSS in the course of this description) together in one big file.

Various approaches have been used for this so far, the most common ones are these:

* Using some sort of Makefile or similar process, collect the assets, combine them and then link them. This works very nicely, but the additional deployment step is easily forgotten and even if you recompile the assets, old versions of the compiled file could (and should!) be stored in the clients cache.
* Serving all assets together using a specially created gateway script. This allows you to forego the compilation process, but you have to be very careful not to break client side caching when you use any server side scripting language.

Both solutions also require you to keep them in mind during development: The first one requires you to check for the existence of the compiled file and serve that or the individual files while the second one forces you to do some kind of registry to notify the central asset serving script about the whereabouts of the file.

Smarty Asset Compiler (sacy) is (as the name suggests) a Plugin for the widely used PHP templating engine [Smarty](http://www.smarty.net) that provides a fresh approach that solves (nearly) all problems with the traditional solutions.

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
          {/asset_compile}
        </head>
        <body>
        content
        </body>
    </html>

At request time, sacy will parse the content of the block, extract the CSS (JavaScript is planned) links, check wehtether it has already cached the compiled version. If not, it'll create one directly on the file system.

At any rate, it'll remove the old `<link>`-tags and add a new one, so that your HTML will look like this:

    <html>
        <head>
          <title>Title</title>
          <link type="text/css" rel="stylesheet" href="/csscache/file1-file2-file3-file4-abc1234def12345.css" />
        </head>
        <body>
        content
        </body>
    </html>

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

Installation
------------

1. Place `block.asset_compile.php` in your smarty plugin directory
2. Edit the two constants at the top. The `OUTPUT_DIR` is where you want the files written to, `URL_ROOT` is how that directoy is accessible from the outside.
3. there is no step 3

I recommend setting `OUTPUT_DIR` to some place that's not publicly accessible and then using symlinks or a webserver level alias directory to make just that directory accessible to the outside. You do not want world-writable directories exposed more than what's absolutely needed.

Known Issues
------------

* At the time of this writing, only CSS `<link>`-tags are supported.
* There is no support for the media attribute. All CSS files will be placed together regardless of media type.
* sacy does not contain a CSS parser and changes the path of the CSS that's served, so *relative imports using @import will not work*

I'm actively working on issues one and two - the last one is trickier and I'm not sure whether to really actually do it, especially as absolute @import's will work, albeit using the single file, not the compiled file.

Acknowledgements
----------------

This is based around the blog entry [How we hash our Javascript for better caching and less breakage on updates](http://blog.greenfelt.net/2009/09/01/caching-javascript-safely/) with some added simplifications and adapted for use with Smarty.

Thanks for that blog entry and the [accompanying discussions](http://news.ycombinator.com/item?id=799994) on Hacker News. 

Licence
-------

sacy is © 2009 by Philip Hofstetter <phofstetter@sensational.ch> and is licensed under the MIT License which is reprinted in the LICENSE file accompanying this distribution.

If you find this useful, consider dropping me a line, or, if you want, a patch :-)

