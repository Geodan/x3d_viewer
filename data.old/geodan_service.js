var cors = require( 'cors' ) 
var express = require( 'express' );
var compress = require('compression');

var app = express( ); 
var fs = require( 'fs' ); 
var pg = require( 'pg' ); 
var sets = {
	sensors_pk: { file: 'sensors_pk.sql',sql: ''},
	geodan_pk: { file: 'geodan_pk.sql',sql: ''},
	rwspoints: { file: 'rws_points.sql',sql: ''},
	kade: { file: 'bgt_kade.sql',sql: ''},
	steiger: { file: 'bgt_steiger.sql',sql: ''},
	water: { file: 'bgt_water.sql',sql: ''},
	bridge: { file: 'bgt_bridge.sql',sql: ''},
	bridgepilons: { file: 'bgt_bridgepilons.sql',sql: ''},
	scheiding: { file : 'bgt_scheiding.sql',sql: ''},
	terrain: { file : 'bgt_terrain.sql',sql: ''},
	roads: { file : 'bgt_road.sql',sql: ''},
	buildings: { file : 'bgt_buildings.sql', sql : '' },
	roofskeleton: { file : 'bgt_roofskeleton.sql', sql : '' },
	treepoints: { file : 'ahn3_treepoints.sql', sql : '' },
	treepoints_ahn3: { file : 'bgt_ahn3_treepoints.sql', sql : '' },
	treepoints_ahn2: { file : 'bgt_ahn2_treepoints.sql', sql : '' },
	groundpoints: { file: 'bgt_groundpoints.sql', sql: '' },
	lights: { file : 'bgt_lights.sql', sql : '' }
}; 
var header = fs.readFileSync('header.x3d').toString();
var footer = fs.readFileSync('footer.x3d').toString();

for( var s in sets ) { 
	sets [ s ].sql = fs.readFileSync( sets [ s ].file ).toString( ); 
};
app.use( cors( )); 
app.use(compress());  
app.get( '/geodan3d', function( req, res ) { 
		var north = req.query [ 'north' ]; 
		var south = req.query [ 'south' ]; 
		var west = req.query [ 'west' ]; 
		var east = req.query [ 'east' ]; 
		var set = req.query [ 'set' ];
		var eps = req.query [ 'eps' ] || 3;
		var minpoints = req.query [ 'minpoints' ] || 350;
		
		if (!set){
			res.send('Missing parameter');
			console.log('Missing parameter');
		}
		else if (!sets[set]){
			res.send('Not such set found: ' + set);
			console.log('Not such set found: ' + set);
		}
		else {
			var client = new pg.Client( { 
					user : 'geodan', 
					password : 'Gehijm', 
					database : 'research', 
					host : 'metis', 
					port : 5432 
			} ); 
			
			var querystring = fs.readFileSync( sets [ set ].file ).toString( );
			client.connect( function( err ) { 
					if( err ) {
						res.send(err)
					} 
					console.log('Set: ',set);
					//var querystring = sets [ set ].sql; 
					querystring = querystring
						.replace( /_west/g, west )
						.replace( /_east/g, east )
						.replace( /_south/g, south )
						.replace( /_north/g, north )
						.replace( /_eps/g ,eps)
						.replace( /_minpoints/g ,minpoints)
						.replace( /_zoom/g ,1)
						.replace( /_segmentlength/g,10); 
					client.query( querystring, function( err, result ) { 
							if( err ) { 
								console.warn( err, querystring );
							} 
							var resultstring = header;
							result.rows.forEach( function( row ) {
									resultstring += '<shape render="true" bboxcenter="0,0,0" bboxsize="-1,-1,-1" ispickable="true" class="'+row.type+'" id="586316">';
									resultstring += row.geom; 
									resultstring += '<appearance sorttype="auto" alphaclipthreshold="0.1"><twosidedmaterial ambientintensity="0.2" diffusecolor="salmon" emissivecolor="0,0,0" shininess="0.2" specularcolor="0,0,0" backambientintensity="0.2" backdiffusecolor="0.8,0.8,0.8" backemissivecolor="0,0,0" backshininess="0.2" backspecularcolor="0,0,0" class="material" transparency="0"></twosidedmaterial></appearance>';
									resultstring += '</shape>';
							} );
							resultstring += footer;
							res.set( "Content-Type", 'text/plain' );
							res.send(resultstring);
							/*
							res.set("Content-Type", 'text/javascript'); // i added this to avoid the "Resource interpreted as Script but transferred with MIME type text/html" message
							res.send(JSON.stringify({data: result.rows}));
							*/
							console.log( 'Sending results', result.rows.length ); 
							client.end( ); 
					} ); 
			} );
		}
} );
app.get( '/', function( req, res ) { 
		res.send( 'Nothing to see here, move on!' ); 
} );
app.listen( 8082, function( ) { 
		console.log( 'Geodan X3D service listening on port 8082' ); 
} ); 
