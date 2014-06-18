/*!
 * @copyright Copyright &copy; Pavels Radajevs, 2014
 * @version 1.0.0
 *
 * JQuery Plugin for yii2-fullajax.
 */
(function ($) {
    var cssCache = {};
    var jsCache;
    var lastActiveLink;
    var request;
    var Fjax = function (options) {
        if (window.history && history.pushState) {
            lastActiveLink = $("a[href='" + options.currentUrl + "']");
            jsCache = options.jsCache;
            cssCache = options.cssCache;
            this.$doc = $(document);
            this.$win = $(window);
            this.options = options;
            this.init();
            this.listen();
        } else {
            $(document).trigger('fjax.oldBrowser');
        }
    };

    Fjax.prototype = {
        constructor: Fjax,
        init: function () {
            var self = this;
            $('#'+self.options.contentId).addClass(self.options.contentClass);
        },
        listen: function () {
            var self = this;

            self.$win.on("popstate", function() {
                self.loadPage(location.href);
            });
            self.$doc.on("click", self.options.linkSelector, function() {
                if(request){
                    request.abort();
                }
                var link = $(this),
                    href = link.attr("href");

                history.pushState(null, null,href);

                lastActiveLink.removeClass('active').parent('.active').removeClass('active');
                lastActiveLink = link;
                lastActiveLink.addClass('active').parent('li').addClass('active');

                if(self.loadCachePage(href)){
                    self.trigger('fjax.analytics',[href]);
                    return false;
                }
                self.loadPage(href);
                return false;
            })
        },
        loadPage:function(url){
            var self = this,
                $container = $('.'+self.options.contentClass),
                $newContainer,
                links = [];
            self.trigger('fjax.loading',[true]);
            $('.cachePage:visible').removeAttr('id').hide();

            request = $.ajax({
                url: url,
                type: 'GET',
                dataType: 'json',
                data: self.options.data,
                beforeSend: function(xhr){
                    xhr.setRequestHeader('X-Fjax', 'true');
                    xhr.setRequestHeader('layout', self.options.layout);
                    self.trigger('fjax.ajaxBeforeSend');
                },
                success: function(data){

                    if(data.redirect){
                        location.href = data.redirect;
                        return true;
                    }

                    self.appendCss(data,url);


                    if(data.title){
                        $('title').text(data.title);
                    }

                    if(data.cache){
                        $newContainer = $container.clone(true);
                        $('#'+self.options.contentId).hide().removeAttr('id');
                        $newContainer.addClass('cachePage').removeClass(self.options.contentClass).attr({
                            'data-cache': url,
                            'data-title': data.title,
                            'id': self.options.contentId
                        });
                        $container.after($newContainer);
                        $container = $newContainer;
                    }else{
                        $('#' + self.options.contentId).hide().removeAttr('id');
                        $container.attr('id',self.options.contentId);
                    }

                    if(data.scripts){
                        for (var i in data.scripts.links) {

                            var link = data.scripts.links[i];
                            if(jsCache[link]){
                                continue;
                            }else if($("script[src='"+link+"']").size()){
                                jsCache[link] = true;
                            }else{
                                links.push(link);
                                jsCache[link] = true;
                            }
                        }
                    }

                    if(links.length){
                        getScripts(links,function(){
                            self.appendContent($container,data);
                        });
                    }else{
                        self.appendContent($container,data);
                    }
                    self.trigger('fjax.analytics',[url]);
                },
                complete:function(jqXHR,textStatus){
                    self.trigger('fjax.ajaxComplete',[jqXHR,textStatus]);
                },
                error:function(jqXHR,textStatus,message){
                    self.trigger('fjax.ajaxError',[jqXHR,textStatus,message]);
                }
            });
        },
        trigger: function(event,params){
            this.$doc.trigger(event,params || []);
        },
        appendContent:function($container,data){
            var self = this;
            if(data.content){
                $container.html(data.content).show();
            }
            self.trigger('fjax.loading',[false]);
            if(data.scripts){
                var o = {initFunc:false,readyFunc:false};
                if(data.scripts.init){
                    o.initFunc = eval("("+d.scripts.init+")");
                    o.initFunc();
                }
                if(data.scripts.ready){
                    o.readyFunc = eval("("+d.scripts.ready+")");
                    o.readyFunc();
                }
                if(data.cache){
                    $container.data('fjax',o);
                }

            }
        },
        loadCachePage:function(url){
            var self = this,
                cacheDiv = $('[data-cache="'+url+'"]');
            if(cacheDiv.size()){
                $('#' + self.options.contentId).removeAttr('id').hide();
                $('title').text(cacheDiv.attr('data-title'));
                cacheDiv.attr('id',self.options.contentId).show();
                var f = cacheDiv.data('fjax');
                if(f){
                    if($.isFunction(f.initFunc)){
                        f.initFunc();
                    }
                    if($.isFunction(f.readyFunc)){
                        f.readyFunc();
                    }
                }
                return true;
            }
            return false;
        },
        appendCss:function(data,url){
            var self = this;
            if(data.css){

                if(data.css.links && data.css.links.length){
                    var cssHtmlLink = '',
                        links = data.css.links;
                    for (var i in links) {
                        if(links.hasOwnProperty(i) && !cssCache[links[i]]){
                            cssHtmlLink += '<link rel="stylesheet" href="'+links[i]+'">';
                            cssCache[links[i]] = true;
                        }
                    }
                    if(cssHtmlLink){
                        $('head').append(cssHtmlLink);
                    }
                }
                if(data.css.code){
                    if(!cssCache[url]){
                        $('head').append('<style type="text/css">'+data.css.code+'</style>');
                        cssCache[url] = true;
                    }
                }
            }
        }
    };


    $.fjax = function (option) {
        var $doc = $(document),
            data = $doc.data('fjax'),
            options = typeof option === 'object' && option;
        if (!data) {
            $doc.data('fjax',new Fjax($.extend({}, $.fjax.defaults, options, $doc.data())));
        }

    };
    $.fjax.defaults = {
        contentId: "content",
        linkSelector: 'a.fjax',
        contentClass: 'contentPage',
        data: {},
        jsCache: {},
        cssCache: {},
        currentUrl: ''
    };
    var getScripts = function( resources, callback ) {

        var // reference declaration &amp; localization
            length = resources.length,
            handler = function() { counter++; },
            deferreds = [],
            counter = 0,
            idx = 0;

        for ( ; idx < length; idx++ ) {
            deferreds.push(
                $.getScript( resources[ idx ], handler )
            );
        }
        $.when.apply( null, deferreds ).then(function() {
            callback();
        });
    };

}(jQuery));