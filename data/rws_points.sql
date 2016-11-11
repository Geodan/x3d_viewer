WITH 
bounds AS (
	SELECT ST_MakeEnvelope(_west, _south, _east, _north, 28992) geom
),
patches AS (
	SELECT a.* FROM rws_pointcloud.zfs a, bounds b
	WHERE ST_Intersects(Geometry(a.pa), b.geom)
	AND x_min < _east::double precision
	AND y_min < _north::double precision
	AND x_max > _west::double precision
	AND y_max > _south::double precision
),
points AS (
	SELECT PC_Explode(pa) pt
	FROM patches
),
points_filtered AS (
	SELECT pt 
	FROM points
	WHERE random() < 0.3
)
SELECT nextval('counter') as id, 'tree' as type, 'white' as color, ST_AsX3D(ST_Collect(Geometry(pt))) geom
FROM points_filtered a;