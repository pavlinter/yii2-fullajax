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
    var afterClose;
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
                var event = {return: false};
                self.trigger('fjax.popstate',[event]);
                if(event.return) {
                    return false;
                }
                self.loadPage(location.href);
            });
            self.$doc.on("click", self.options.linkSelector, function() {
                var event = {return: false};
                self.trigger('fjax.linkClick',[event]);
                if(event.return) {
                    return false;
                }

                if(request){
                    request.abort();
                }
                var link = $(this),
                    href = link.attr("href");

                history.pushState(null, null,href);

                lastActiveLink.removeClass('active').parent('.active').removeClass('active');
                lastActiveLink = $("a[href='" + href + "']");
                lastActiveLink.addClass('active').parent('li').addClass('active');

                self.loadPage(href);
                return false;
            })

            if (!self.options.eventsList['fjax.changePage']){
                self.$doc.on('fjax.changePage', function($event,$newContainer,$oldContainer,data,self) {
                    if ($oldContainer){
                        $oldContainer.remove();
                    }
                    $newContainer.show();
                });
            }
            if (!self.options.eventsList['fjax.changeCachePage']){
                self.$doc.on('fjax.changeCachePage', function($event,$newContainer,$oldContainer,data,self) {
                    $oldContainer.hide();
                    $newContainer.show();
                });
            }
        },
        loadPage:function(url){
            var self = this,
                $oldContainer = $('.'+self.options.contentClass),
                $newContainer,
                links = [];
            if(self.loadCachePage(url)){
                self.trigger('fjax.analytics',[url]);
                return false;
            }


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

                    $newContainer = $oldContainer.clone(true).hide().html(data.content);
                    $oldContainer.hide().removeAttr('id');
                    $oldContainer.after($newContainer);
                    $newContainer.attr('id',self.options.contentId);
                    if(data.cache){
                        $newContainer.addClass('cachePage')
                            .removeClass(self.options.contentClass)
                            .attr('data-cache', url);
                    }

                    if($.isFunction(afterClose)){
                        if(afterClose($oldContainer,self) === false){
                            afterClose = false;
                            return true;
                        }
                        afterClose = false;
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
                            self.appendContent($newContainer,$oldContainer,data);
                        });
                    }else{
                        self.appendContent($newContainer,$oldContainer,data);
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
        appendContent:function($newContainer,$oldContainer,data){
            var self = this,
                o = {initFunc: false, readyFunc: false, data: data};

            if(data.cache){
                $oldContainer = false;
            }
            self.trigger('fjax.changePage',[$newContainer,$oldContainer,data,self]);
            self.trigger('fjax.loading',[false]);

            if(data.scripts){
                if(data.scripts.init){
                    o.initFunc = eval("(function(){" + data.scripts.init + "})");
                    o.initFunc();
                }
                if(data.scripts.ready){
                    o.readyFunc = eval("(function(){" + data.scripts.ready + "})");
                    o.readyFunc();
                }
                if(data.scripts.afterClose){
                    afterClose = o.afterCloseFunc = eval("(function(){" + data.scripts.afterClose + "})");
                }
            }
            $newContainer.data('fjax',o);

        },
        loadCachePage:function(url){
            var self = this,
                $oldContainer,
                settings,
                $cacheDiv = $('[data-cache="'+url+'"]');
            if($cacheDiv.size()){
                $oldContainer = $('#' + self.options.contentId).removeAttr('id');
                if($.isFunction(afterClose)){
                    if(afterClose($oldContainer,self) === false){
                        afterClose = false;
                        return true;
                    }
                    afterClose = false;
                }

                settings = $cacheDiv.data('fjax');

                $('title').text(settings.title);
                $cacheDiv.attr('id',self.options.contentId);
                self.trigger('fjax.changeCachePage',[$cacheDiv,$oldContainer,settings.data,self]);

                if(settings){
                    if($.isFunction(settings.initFunc)){
                        settings.initFunc();
                    }
                    if($.isFunction(settings.readyFunc)){
                        settings.readyFunc();
                    }
                    if($.isFunction(settings.afterCloseFunc)){
                        afterClose = settings.afterCloseFunc;
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
        eventsList: {},
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