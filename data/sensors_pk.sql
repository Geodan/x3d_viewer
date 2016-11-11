WITH 
bounds AS (
	SELECT ST_Segmentize(ST_MakeEnvelope(_west, _south, _east, _north, 28992),_segmentlength) geom
)
,sensors AS (
	SELECT id,
			ST_Buffer(a.geom,0.2)
		 AS geom 
	FROM geodangebouw.adam_sensoren a, bounds b
	WHERE ST_Intersects(a.geom,b.geom)
)

SELECT nextval('counter') as id,
'red' color, ST_AsX3D(ST_Extrude(
		ST_Translate(
		p.geom
		,0,0,7)	
	,0,0,1)
		) geom
FROM sensors p;
