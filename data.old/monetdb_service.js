var cors = require( 'cors' )
var express = require( 'express' );
var compress = require('compression');

var app = express( );
var fs = require( 'fs' );
var MDB = require('monetdb')();
var sets = {
        terrain: { file : 'monetdb_terrain.sql',sql: ''},
        treepoints: { file : 'monetdb_treepoints.sql',sql: ''}
};
for( var s in sets ) {
        sets [ s ].sql = fs.readFileSync( sets [ s ].file ).toString( );
};
app.use( cors( ));
app.use(compress());
app.get( '/monetdb_3d', function( req, res ) {
                var north = req.query [ 'north' ];
                var south = req.query [ 'south' ];
                var west = req.query [ 'west' ];
                var east = req.query [ 'east' ];
                var set = req.query [ 'set' ] || 'terrain';
                //var client = require('monetdb')();

                var options = {
                    host     : 'metis',
                    port     : 55000,
                    dbname   : 'research',
                    user     : 'monetdb',
                    password : 'monetdb'
                };
                var querystring = sets [ set ].sql;
                                querystring = querystring
                                        .replace( /_west/g, west )
                                        .replace( /_east/g, east )
                                        .replace( /_south/g, south )
                                        .replace( /_north/g, north )
                                        .replace( /_zoom/g ,1)
                                        .replace( /_segmentlength/g,10);
                console.log('running: ',querystring);
                var client = new MDB(options);
                var p = client.connect();
                p.then(function( err ) {
                                if( err ) {
                                        res.send( 'could not connect to monetdb');
                                }
                                console.log('Set: ',set);
                                client.query( querystring).then(function( result) {
                                                if( err ) {
                                                        console.warn( err, querystring );
                                                }
                                                //console.log(querystring);
                                                var resultstring = 'id;type;geom;';
                                                //for (var key in result.data[0]){
                                                //      resultstring += key + ';'
                                                //}

                                                resultstring += "\n";
                                                result.data.forEach( function( row ) {
                                                                for (var key in row){
                                                                        resultstring += row[key] + ';'
                                                                }               
                                                                resultstring += '\n';
                                                } );                            
                                                res.set( "Content-Type", 'text/plain' );
                                                res.send(resultstring);         
                                                /*                              
                                                res.set("Content-Type", 'text/javascript'); // i added this to avoid the "Resource interpreted as Script but tra                                     nsferred with MIME type text/html" message
                                                res.send(JSON.stringify({data: result.rows}));
                                                */                              
                                                console.log( 'Sending results', result.data.length );
                                                                                
                                } ).catch(function(e){                          
                                        console.warn('node did a boo boo',e);   
                                });                                             
                } );                                                            
} );                                                                            
                app.get( '/', function( req, res ) {                            
                                res.send( 'Nothing to see here, move on!' );    
                } );                                                            
                app.listen( 8083, function( ) {                                 
                                console.log( 'BGT X3D service listening on port 8083' );
                } );
