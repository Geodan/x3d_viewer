WITH 
bounds AS (
	SELECT ST_MakeEnvelope(_west, _south, _east, _north, 28992) geom
),
treelocations AS (
	SELECT ogc_fid,wkb_geometry 
	FROM bgt_import2.vegetatieobject_2dactueelbestaand, bounds
	WHERE ST_Intersects(geom, wkb_geometry)
),
buildings AS (
	SELECT ST_Union(wkb_geometry) wkb_geometry 
	FROM bgt_import2.pand_2dactueelbestaand, bounds
	WHERE ST_Intersects(geom, wkb_geometry)
),
patches AS(
	SELECT 
	 pa  
	FROM ahn2_pointcloud.u30fz1, bounds 
	WHERE ST_DWithin(geom, Geometry(pa),10) --patches should be INSIDE bounds
),
treemeta AS (
	SELECT ogc_fid, wkb_geometry, 
	Max(PC_PatchMax(pa,'z')) As top,
	Min(PC_PatchMin(pa,'z')) AS bottom
	FROM patches 
	INNER JOIN treelocations ON St_DWithin(wkb_geometry,Geometry(pa), 5) 
	GROUP BY ogc_fid, wkb_geometry
)
,points AS (
	SELECT PC_Explode(pa) pt
	FROM patches
),
points_filtered AS (
	SELECT Geometry(pt) geom, points.*
	FROM points 
	INNER JOIN treelocations ON St_DWithin(wkb_geometry,Geometry(pt), 7)
	INNER JOIN buildings x ON Not ST_DWithin(x.wkb_geometry, Geometry(pt),0.5)
)
,clusters As (
	--SELECT ST_CollectionHomogenize(unnest(ST_ClusterWithin(Geometry(pt),0.5))) as geom
	SELECT geom, pt,
	--ST_ClusterKMeans(geom,20) over () AS cid
	ST_ClusterDBScan(geom, eps := _eps, minpoints := _minpoints) over () AS cid
	FROM points_filtered a
)

SELECT cid as id, 
'tree' as type, 
'blue' as color, 
ST_AsX3D(ST_Collect(geom)) geom,
MAX(PC_Get(pt,'z')) - MIN(PC_Get(pt,'z')) as height

FROM clusters a
WHERE cid Is Not Null
GROUP BY cid;