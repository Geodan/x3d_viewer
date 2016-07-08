SELECT _south::text || _west::text || '1' AS id, 'land' AS type,
St_AsX3D(ST_SetSrid(ST_Triangulate2DZ(ST_Collect(ST_SetSrid(St_MakePoint(x,y),28992)), 0),28992),3,1)  
FROM ahn3 
WHERE x > CAST(_west3 AS double) AND x < CAST(_east AS double) and y between CAST(_south AS double) and CAST(_north AS double);
