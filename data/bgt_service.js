var cors = require( 'cors' ) 
var express = require( 'express' );
var compress = require('compression');

var app = express( ); 
var fs = require( 'fs' ); 
var pg = require( 'pg' ); 
var sets = {
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
for( var s in sets ) { 
	sets [ s ].sql = fs.readFileSync( sets [ s ].file ).toString( ); 
};
app.use( cors( )); 
app.use(compress());  
app.get( '/bgt3d', function( req, res ) { 
		var north = req.query [ 'north' ]; 
		var south = req.query [ 'south' ]; 
		var west = req.query [ 'west' ]; 
		var east = req.query [ 'east' ]; 
		var set = req.query [ 'set' ] || 'buildings';
		var eps = req.query [ 'eps' ] || 3;
		var minpoints = req.query [ 'minpoints' ] || 350;
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
					res.status('could not connect to postgres').send(err)
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
						//console.log(querystring);
						var resultstring = '';
						for (var key in result.rows[0]){
							resultstring += key + ';'
						}
						resultstring += "\n"; 
						result.rows.forEach( function( row ) {
								for (var key in row){
									resultstring += row[key] + ';' 
								}
								resultstring += '\n';
						} );
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
} );
		app.get( '/', function( req, res ) { 
				res.send( 'Nothing to see here, move on!' ); 
		} );
		app.listen( 8081, function( ) { 
				console.log( 'BGT X3D service listening on port 8081' ); 
		} ); 
