WITH 
bounds AS (
	--SELECT ST_Buffer(ST_Transform(ST_SetSrid(ST_MakePoint(_lon, _lat),4326), 28992),200) geom
	SELECT ST_MakeEnvelope(_west, _south, _east, _north, 28992) geom
), 
polygons AS (
	SELECT ogc_fid as id,
	wkb_geometry as geom
	FROM adam3dfied.onbegroeidterreindeel a
	INNER JOIN bounds b ON ST_Contains(b.geom,a.wkb_geometry)
)
SELECT id,
--s.type as type,
'OnbegroeidTerreindeel' as type,
'yellow' color, ST_AsX3D(geom) geom
FROM polygons p;