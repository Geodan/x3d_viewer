WITH 
walls AS (
	SELECT id,h_floor,h_ceil,
	--ST_Buffer(
		ST_ExteriorRing(
			ST_Reverse((ST_Dump((a.geom))).geom)
		)
	--,0.2,'endcap=flat join=mitre') 
	AS geom 
	FROM geodangebouw.adam_ruimtes_all a
	--AND a.omschrijvi = 'Vergaderruimte'
),
floors AS (
	SELECT verdieping AS id,h_floor,h_ceil,
	(ST_Dump(ST_Union(a.geom))).geom 
	FROM geodangebouw.adam_ruimtes_all a
	GROUP BY verdieping, h_floor, h_ceil
)
,walls_extruded AS (
	SELECT 
		id || 'w' AS id,'wall'::text as type,
			ST_Extrude(
				geom
			, 0,0,h_ceil-h_floor)
		geom FROM walls
)
,doors_extruded AS (
	SELECT id,h_floor,h_ceil,
	St_Extrude(
		ST_Translate(
			ST_Buffer(a.geom,1,'endcap=flat join=round')
		,0,0,h_floor)
	,0,0,h_ceil-h_floor) geom 
	FROM geodangebouw.adam_ramendeuren_all a
	WHERE type = 'deur'
)
,walls_withdoors AS (
	SELECT a.id, a.type,
	COALESCE(ST_3DDifference(a.geom, b.geom),a.geom) geom
	FROM walls_extruded a
	LEFT JOIN doors_extruded b
	ON ST_3DIntersects(a.geom, b.geom)
)
,floors_extruded AS (
	SELECT 
		id || 'f' AS id,'floor'::text as type,
		(
			ST_Extrude(geom,0,0,0.1)
		)
		geom FROM floors
)
,extrusions AS (
	SELECT * FROM floors_extruded
	UNION ALL
	SELECT * FROM walls_withdoors --doors not rendered well yet
)
SELECT nextval('counter') as id,
type AS type,
'ivory' color, ST_AsX3D(p.geom) geom
FROM extrusions p;
