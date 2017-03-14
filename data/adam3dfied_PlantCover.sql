WITH 
bounds AS (
	--SELECT ST_Buffer(ST_Transform(ST_SetSrid(ST_MakePoint(_lon, _lat),4326), 28992),200) geom
	SELECT ST_MakeEnvelope(_west, _south, _east, _north, 28992) geom
), 
polygons AS (
	SELECT ogc_fid as id,
	wkb_geometry as geom
	FROM adam3dfied.plantcover a
	INNER JOIN bounds b ON ST_Contains(a.wkb_geometry, b.geom)
)
SELECT id,
--s.type as type,
'PlantCover' as type,
'darkgreen' color, ST_AsX3D((p.geom)) geom
FROM polygons p