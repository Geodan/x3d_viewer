WITH 
bounds AS (
	--SELECT ST_Buffer(ST_Transform(ST_SetSrid(ST_MakePoint(_lon, _lat),4326), 28992),200) geom
	SELECT ST_MakeEnvelope(_west, _south, _east, _north, 28992) geom
), 
polygons AS (
	SELECT
	c.id as root,
	d.gmlid as id,
	--x3d_diffuse_color as color,
	d.solid_geometry as geom
	--c.lod2_terrain_intersection as geom
	FROM citydb.cityobject a
	INNER JOIN bounds b ON ST_Intersects(a.envelope, b.geom)
	INNER JOIN citydb.building c ON (a.id = c.id)
	INNER JOIN citydb.surface_geometry d ON (c.lod2_solid_id = d.id)
	--INNER JOIN citydb.surface_data e ON (c.lod2_solid_id = e.id)
	--WHERE c.building_root_id = c.id
)

SELECT p.id,
--s.type as type,
'roof' as type,
ST_AsX3D(
geom
) geom
FROM polygons p