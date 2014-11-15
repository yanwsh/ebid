/**
 * @project ebid
 * @file directives.js
 * @author Wensheng Yan
 * @date Nov 4, 2014
 * (c) 2007 - 2014 Wensheng Yan
 */
define("directives", ['angular','jquery','elevatezoom','fancybox'], function(angular, $){
	var app = angular.module('ebid/directives', []);
	app.directive('ngElevateZoom', function() {
		  return {
		    restrict: 'A',
		    link: function(scope, element, attrs) {

		      //Will watch for changes on the attribute
		      attrs.$observe('zoomImage',function(){
		        linkElevateZoom();
		      })

		      function linkElevateZoom(){
		        //Check if its not empty
		        if (!attrs.zoomImage) return;
		        element.attr('data-zoom-image',attrs.zoomImage);
		        var options = {};
		        if(attrs.kOption)
		          options = $.parseJSON(attrs.kOption);
		        $(element).elevateZoom(options);
		        $(element).bind("click", function(e){
		        	var ez = $(element).data('elevateZoom');
		        	$.fancybox(ez.getGalleryList());
		        	return false;
		        });
		      }

		      linkElevateZoom();

		    }
		  };
		});
	return app;
});