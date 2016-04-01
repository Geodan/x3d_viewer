var cors = require( 'cors' ) 
var express = require( 'express' );
var compress = require('compression');

var app = express( ); 
var fs = require( 'fs' ); 
var pg = require( 'pg' ); 
var sets = {
	kade: { file: 'bgt_kade.sql',sql: ''},
	scheiding: { file : 'bgt_scheiding.sql',sql: ''},
	terrain: { file : 'bgt_terrain.sql',sql: ''},
	buildings: { file : 'bgt_buildings.sql', sql : '' },
	roofskeleton: { file : 'bgt_roofskeleton.sql', sql : '' },
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
		var client = new pg.Client( { 
				user : 'geodan', 
				password : 'password', 
				database : 'dev', 
				host : 'titania', 
				port : 5432 
		} ); 
		client.connect( function( err ) { 
				if( err ) { 
					res.send( 'could not connect to postgres' ); 
				} 
				console.log('Set: ',set);
				var querystring = sets [ set ].sql; 
				querystring = querystring
					.replace( /_west/g, west )
					.replace( /_east/g, east )
					.replace( /_south/g, south )
					.replace( /_north/g, north )
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