<?php
# OpenStreetMap SlippyMap - MediaWiki extension
#
# This defines what happens when <slippymap> tag is placed in the wikitext
#
# We show a map zoomed on the central lat/lon data passed.
# This extension brings in the OpenLayers javascript, to show a slippy map.
#
# Usage example:
# <slippymap lat="51.485" lon="-0.15" z="11" w="300" h="200" layer="osmarender" marker="0">content</slippymap>
# <slippymap lat="51.485" lon="-0.15" z="11" w="300" h="200" layer="osmarender" marker="0"/>
# or:
# {{#tag:slippymap|content|lat=51.485|lon=-0.15|z=11|w=300|h=200|layer=mapnik|marker=0}}
# {{#tag:slippymap||lat=51.485|lon=-0.15|z=11|w=300|h=200|layer=mapnik|marker=0}}
#
# Tile images are not cached locally by the wiki but by the web client querying the tile server.
# To achieve this (remove the OSM dependency) you might set up a squid proxy,
# and modify the requests URLs here accordingly (using your own layer constructors).
# An OSM-specific script defines some constructors for supported layers.
#
# This file should be placed in the mediawiki 'extensions' directory
# ... and then it needs to be 'included' within LocalSettings.php
#
# #################################################################################
#
# Copyright 2008 Harry Wood, Jens Frank, Grant Slater, Raymond Spekking and others
#
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
#
# @addtogroup Extensions
#
class SlippyMap {
	# The callback function for converting the input text to HTML output
	static function parse( $input, $argv ) {
		global $wgScriptPath, $wgMapOfServiceUrl/*, $wgSlippyMapVersion*/;

		//wfLoadExtensionMessages( 'SlippyMap' );// Not needed in MW 1.21
		static function T( $id, $a ) {
			return wfMessage( $id, $a )->text();
		}

		// Receive parameters of the form:
		// {{#tag:slippymap|input|aaa=bbb|ccc=ddd}}, or
		// <slippymap aaa="bbb" ccc="ddd">input</slippymap>, or
		// <slippymap aaa="bbb" ccc="ddd"/>
		$error = '';

		// Parse the mandatory parameters
		$lat = isset( $argv['lat'] ) ? $argv['lat'] : '';
		if ( $lat == ''  ) $error .= T( 'slippymap_latmissing' ) . '<br/>';
		else if ( !is_numeric( $lat ) ) $error .= T( 'slippymap_latnan', htmlspecialchars( $lat ) ) . '<br/>';
		else if ( $lat < -90 ) $error .= T( 'slippymap_latsmall', $lat ) . '<br/>';
		else if ( $lat > 90 ) $error .= T( 'slippymap_latbig', $lat ) . '<br/>';

		$lon = isset( $argv['lon'] ) ? $argv['lon'] : '';
		if ( $lon == ''  ) $error .= T( 'slippymap_lonmissing' ) . '<br/>';
		else if ( !is_numeric( $lon ) ) $error .= T( 'slippymap_lonnan', htmlspecialchars( $lon ) ) . '<br/>';
		else if ( $lon < -180 ) $error .= T( 'slippymap_lonsmall', $lon ) . '<br/>';
		else if ( $lon > 180 ) $error .= T( 'slippymap_lonbig', $lon ) . '<br/>';

		$zoom = isset( $argv['z'] ) ? $argv['z'] : '';
		if ( $zoom == '' && isset( $argv['zoom'] ) ) $zoom = $argv['zoom']; // see if they used 'zoom' rather than 'z' (and allow it)
		if ( $zoom == '' ) $error .= T( 'slippymap_zoommissing' ) . '<br/>';
		else if ( !is_numeric( $zoom ) ) $error .= T( 'slippymap_zoomnan', htmlspecialchars( $zoom ) ) . '<br/>';
		else if ( $zoom < 0 ) $error .= T( 'slippymap_zoomsmall', $zoom ) . '<br/>';
		else if ( $zoom == 18 ) $error .= T( 'slippymap_zoom18', $zoom ) . '<br/>';
		else if ( $zoom > 18 ) $error .= T( 'slippymap_zoombig', $zoom ) . '<br/>';

		// Parse the supported optional parameters
		// Trim off the 'px' on the end of pixel measurement numbers (ignore if present)
		$width  = isset( $argv['w'] ) ? $argv['w'] : '';
		if ( $width  == '' ) $width = '450';
		else if ( substr( $width, -2 ) == 'px' ) $width = (int) substr( $width, 0, -2 );
		if ( !is_numeric( $width ) ) $error .= T( 'slippymap_widthnan', htmlspecialchars( $width ) ) . '<br/>';
		else if ( $width < 100 ) $error .= T( 'slippymap_widthsmall', $width ) . '<br/>';
		else if ( $width > 1000 ) $error .= T( 'slippymap_widthbig', $width ) . '<br/>';

		$height = isset( $argv['h'] ) ? $argv['h'] : '';
		if ( $height == '' ) $height = '320';
		else if ( substr( $height, - 2 ) == 'px' ) $height = (int) substr( $height, 0, -2 );
		if ( !is_numeric( $height ) ) $error .= T( 'slippymap_heightnan', htmlspecialchars( $height ) ) . '<br/>';
		else if ( $height < 100 ) $error .= T( 'slippymap_heightsmall', $height ) . '<br/>';
		else if ( $height > 1000 ) $error .= T( 'slippymap_heightbig', $height ) . '<br/>';

		$layer  = isset( $argv['layer'] ) ? $argv['layer'] : '';
		if ( $layer  == '' ) $layer = 'mapnik';
		// Find the tile server URL to use. Note that we could allow the user to override that with
		// *any* tile server URL for more flexibility, but that might be a security concern. Here the
		// supported layers must use 'OpenLayers.Layer.OSM.*' constructors defined in the OSM Javascript.
		$layer = strtolower( $layer );
		if ( $layer == 'mapnik' ) $layerObjectDef = 'Mapnik("Mapnik")';
		elseif ( $layer == 'cycle' ) $layerObjectDef = 'CycleMap("OpenCycleMap")';
		elseif ( $layer == 'transport' ) $layerObjectDef = 'TransportMap("Transport")';
		else $error .= T( 'slippymap_invalidlayer', htmlspecialchars( $layer ) );

		$marker = isset( $argv['marker'] ) ? $argv['marker'] : '';
		$marker = ( $marker != '' && $marker != '0' );
		if ( $marker ) $error .= T( 'slippymap_unsupportedmarker' ) . '<br/>';
		$marker = false;

		// Parse the optional contents
		$input = trim($input);
		if ( $input != '' ) {
			if (strpos($input, '|') !== false)
				$error .= T( 'slippymap_unsupportedoldcontents' ) . '<br/>';
			else
				$error .= T( 'slippymap_unsupportedkmlcontents' ) . '<br/>';
		}
		$showkml = false;

		if ( $error != "" ) // Something was wrong. Spew the error message and input text.
			return '<span class="error">' . T( 'slippymap_maperror' ) . '<br/>' . $error . '</span>' .
				htmlspecialchars( $input );

		// HTML output for the slippy map.
		// Note that this must all be output on one line (no linefeeds)
		// otherwise MediaWiki adds <br/> tags, which is bad in the middle of a block of javascript.
		// There are other ways of fixing this, but not for MediaWiki v4
		// (See http://www.mediawiki.org/wiki/Manual:Tag_extensions#How_can_I_avoid_modification_of_my_extension.27s_HTML_output.3F)
		$output  = 
			// Bring in the OpenLayers javascript library (load in parallel).
			'<script src="//openstreetmap.org/openlayers/OpenLayers.js" async></script>' .
			'// Slippy map container
			'<div id="map" style="border:1px solid #AAA;width:' . $width . 'px;height:' . $height . 'px"><noscript>' .
				'<a href="//www.openstreetmap.org/?lat=' . $lat . '&lon=' . $lon . '&zoom=' . $zoom .
				'" title="See this map on OpenStreetMap.org" style="text-decoration:none">' .
					'<img border="0" width="' . $width . '" height="' . $height . '" src="' .
					$wgMapOfServiceUrl . 'format=jpeg' .
					'&lat=' . $lat . '&long=" . $lon . '&z=" . $zoom .
					'&w=" . $width . '&h=" . $height .
					'" alt="Slippy Map"/>' .
				'</a>' .
			'</noscript></div>' .
			// Theses two scripts are defered to run orderly after DOM is parsed and after async scripts are loaded.
			// 1. Bring in the OpenStreetMap layers defined for OpenLayers. Using this hosted file will make sure we
			// are kept up to date with any necessary changes.
			'<script src="//openstreetmap.org/openlayers/OpenStreetMap.js" defer></script>' .
			// 2. Configure the slippy map in the document.
			'<script type="text/javascript" defer>' .
			'function slippymap_init(){' .
				'var map=new OpenLayers.Map("map",{' .
						'projection:"EPSG:900913",units:"meters",maxResolution:156543.0399,' .
						'maxExtent:new OpenLayers.Bounds(-20037508.34,-20037508.34,20037508.34,20037508.34),' .
						'controls:[' .
							'new OpenLayers.Control.Navigation(),' .
							// Add the zoom bar control, except if the map is too little
							( $height > 320 ? 'new OpenLayers.Control.PanZoomBar(),'
							: $height > 140 ? 'new OpenLayers.Control.PanZoom(),'
							: '' ) .
							'new OpenLayers.Control.Attribution()' .
						']}),' .
					'epsg4326=new OpenLayers.Projection("EPSG:4326");' .
				'map.addLayer(new OpenLayers.Layer.OSM.' . $layerObjectDef . ');';
		if ( $showkml )
			$output .=
				'var layer=new OpenLayers.Layer.Vector("Vector Layer");' .
				'layer.addFeatures(' .
					'new OpenLayers.Format.KML({' .
						'internalProjection:map.baseLayer.projection,' .
						'externalProjection:epsg4326,' .
						'extractAttributes:true,' .
						'extractStyles:true' .
					'}).read(unescape(' .
						str_replace(
							array( '%',   "\n" , "'"  , '"'  , '<'  , '>'  , ' '   ),
							array( '%25', '%0A', '%27', '%22', '%3C', '%3E', '%20' ),
							$input ) .
					')));' .
				'map.addLayer(layer);';
		if ( $marker )
			$output .=
				'var size=new OpenLayers.Size(20,34),offset=new OpenLayers.Pixel(-(size.w/2),-size.h),' .
					'icon=new OpenLayers.Icon("http://boston.openguides.org/markers/YELLOW.png",size,offset),' .
					'layer=new OpenLayers.Layer.Markers("Markers"),' .
				'layer.addMarker(new OpenLayers.Marker(map.initLonLat,icon));';
				'map.addLayer(layer);';
		$output .=
				'var lonLat=new OpenLayers.LonLat(' . $lon ',' . $lat ').transform(epsg4326,map.getProjectionObject()),' .
					'slippymap_resetPosition=function(){map.setCenter(lonLat,' . $zoom . ')},' .
					'panel=new OpenLayers.Control.Panel({displayClass:"buttonsPanel"});' .
				'panel.addControls([' .
					'new OpenLayers.Control.Button({' .
						'title:"' . T( 'slippymap_resetview' ) . '",' .
						'displayClass:"resetButton",' .
						'trigger:slippymap_resetPosition' .
					'}),' .
					'new OpenLayers.Control.Button({' .
						'title:"' .T( 'slippymap_button_code' ) . '",' .
						'displayClass:"getWikiCodeButton",' .
						'trigger:function(){' .
							'var c=map.getCenter().transform(map.getProjectionObject(),epsg4326),'
								's=map.getSize();' .
							'prompt("' . T( 'slippymap_code' ) .
								'","<slippymap layer=\\"' . $layer . '\\" z=\\"+map.getZoom()+\\"' .
								' lat=\\"+c.lat+\\" lon=\\"+c.lon+\\" h=\\"+s.h+\\" w=\\"+s.w+\\"/>");' .
						'}' .
					'})' .
				']);' .
				'map.addControl(panel);' .
				'slippymap_resetPosition(map);' .
			'}' .
			//'addOnloadHook(slippymap_init);' . // broken since MW 1.17
			'$(window).on("load",slippymap_init)'; // now using jQuery (part of MediaWiki)
			'</script>' .
			// This inline stylesheet defines how the two extra buttons look, and where they are positioned.
			'<style>' .
			'.buttonsPanel div{float:left;position:relative;margin:5px;height:19px;width:36px}' .
			'.buttonsPanel .resetButtonItemInactive{background-image:url("' .
				$wgScriptPath . '/extensions/SlippyMap/reset-button.png");width:36px;height:19px}' .
			'.buttonsPanel .getWikiCodeButtonItemInactive{background-image:url("' .
				$wgScriptPath . '/extensions/SlippyMap/wikicode-button.png");width:36px;height:19px}' .
			'</style>';
		return $output;
	}
}
