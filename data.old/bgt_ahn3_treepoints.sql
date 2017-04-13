WITH 
bounds AS (
	SELECT ST_MakeEnvelope(_west, _south, _east, _north, 28992) geom
),
treelocations AS (
	SELECT a.*
	FROM bgt.vegetatieobject_2dactueelbestaand a, bounds
	WHERE ST_Intersects(geom, wkb_geometry)
),
pointcloud_unclassified AS(
	SELECT 
		PC_FilterEquals(pa,'classification',1)
	 pa  
	FROM ahn3_pointcloud.vw_ahn3, bounds 
	WHERE ST_DWithin(geom, Geometry(pa),10) --patches should be INSIDE bounds
),
patches AS (
	SELECT a.pa FROM pointcloud_unclassified a

),
points AS (
	SELECT PC_Explode(pa) pt
	FROM patches
),
points_filtered AS (
	SELECT ogc_fid, Geometry(pt) geom, points.*
	FROM points 
	INNER JOIN treelocations ON St_DWithin(wkb_geometry,Geometry(pt), 7) --points within 7 meter of tree 
	WHERE PC_Get(pt,'ReturnNumber') < PC_Get(pt,'NumberOfReturns') -1
	AND PC_Get(pt,'Intensity') < 150
)
,clusters As (
	SELECT ogc_fid, geom, pt,
	ST_ClusterDBScan(geom, eps := _eps, minpoints := _minpoints) over () AS cid
	FROM points_filtered a
)
SELECT cid as id, 
'tree' as type, 
'lightGreen' as color, 
ST_AsX3D(ST_Collect(geom)) geom,
MAX(PC_Get(pt,'z')) - MIN(PC_Get(pt,'z')) as height

FROM clusters a
WHERE cid Is Not Null
GROUP BY cid;