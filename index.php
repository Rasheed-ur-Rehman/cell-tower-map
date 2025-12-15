<style>

/* style.css - external stylesheet for the enhanced towers viewer */

html,body,#map { height: 100%; margin:0; padding:0; }
#map { position: absolute; top:0; left:0; right:320px; bottom:0; } /* leave space for right panel */
.right-panel {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 300px;
  max-height: calc(100% - 24px);
  background: rgba(255,255,255,0.98);
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.18);
  padding: 16px;
  overflow: auto;
  font-family: Inter, Roboto, Arial, sans-serif;
  z-index: 2000;
}
.panel-title { font-size: 17px; font-weight:800; margin-bottom:8px; color:#111; }
.stat { margin:8px 0; font-size: 14px; color:#222; }
.legend-row { display:flex; align-items:center; gap:10px; margin:8px 0; }
.legend-color { width:24px; height:14px; border-radius:4px; border: 1px solid rgba(0,0,0,0.08); box-shadow: inset 0 -2px 4px rgba(0,0,0,0.08); }
.small { font-size:12px; color:#555; }
.floating-tower {
  pointer-events: none;
  position: absolute;
  width: 40px;
  height: 40px;
  transform: translate(-50%,-50%);
  z-index: 2500;
  display:none;
  filter: drop-shadow(0 4px 10px rgba(0,0,0,0.45));
}
.line-tooltip { background:rgba(255,255,255,0.98); padding:8px 10px; border-radius:8px; border-left:4px solid #3498db; font-size:13px; box-shadow:0 8px 20px rgba(0,0,0,0.12); }

/* Buttons */
.layer-btn { padding:8px 10px; border-radius:8px; border:1px solid rgba(0,0,0,0.07); background:#fff; cursor:pointer; font-weight:600; }
.layer-btn.active { background:#111; color:#fff; border-color:rgba(0,0,0,0.2); }

/* make controls responsive */
@media (max-width:900px){
  #map { right: 0; }
  .right-panel{ width: 100%; left:0; right:0; bottom:0; top:auto; max-height: 260px; }
}

/* Styling for glow and main lines (optional classes) */
.line-glow { filter: blur(1px); }
.line-main { }

/* Marker cluster custom */
.leaflet-marker-icon { border-radius:4px; }

/* small input tweaks */
#searchInput { font-size:13px; }

/* make popups a little nicer */
.leaflet-popup-content-wrapper{ border-radius:10px; }

/* Location indicator */
.location-indicator {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 1000;
  background: rgba(255, 255, 255, 0.95);
  padding: 10px 15px;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  font-family: sans-serif;
  font-size: 14px;
  display: none;
}

</style>
<?php


$geojsonPath = __DIR__ . '/antenna-structure-registration-asr-geojson.geojson';
if (!file_exists($geojsonPath)) {
    die('GeoJSON file not found: antenna-structure-registration-asr-geojson.geojson');
}

// Load file (decode once in PHP to reduce browser work)
$raw = file_get_contents($geojsonPath);
$data = json_decode($raw, true);

// Basic feature count guard
$totalFeatures = isset($data['features']) ? count($data['features']) : 0;

// Build a light-weight towers array (lat, lon, properties)
$towers = [];
$coordsTypeSet = []; // track unique CoordsType values
if (isset($data['features']) && is_array($data['features'])) {
    foreach ($data['features'] as $f) {
        if (!isset($f['geometry']['coordinates'][0])) continue;
        $lon = floatval($f['geometry']['coordinates'][0]);
        $lat = floatval($f['geometry']['coordinates'][1]);

        $props = isset($f['properties']) ? $f['properties'] : new stdClass();

        // normalize some common keys (you may adjust keys if your file uses different names)
        $height = null;
        if (isset($props['Strucht'])) $height = $props['Strucht'];
        if (isset($props['OVERALL_HEIGHT'])) $height = $props['OVERALL_HEIGHT'];
        if (isset($props['HEIGHT'])) $height = $props['HEIGHT'];

        $weight = null;
        if (isset($props['Weight'])) $weight = $props['Weight'];
        if (isset($props['GROUND_ELEVATION'])) $weight = $props['GROUND_ELEVATION'];

        // CoordsType may be under different keys; attempt common names
        $coordsType = null;
        if (isset($props['CoordsType'])) $coordsType = $props['CoordsType'];
        if (!$coordsType) {
            foreach ($props as $pk => $pv) {
                if (stripos($pk, 'coord') !== false && stripos($pk, 'type') !== false) {
                    $coordsType = $pv; break;
                }
            }
        }
        if ($coordsType) $coordsTypeSet[trim((string)$coordsType)] = true;

        $towers[] = [
            'lat' => $lat,
            'lon' => $lon,
            'props' => $props,
            'height' => $height,
            'weight' => $weight,
            'coordsType' => $coordsType
        ];
    }
}

// create an array of unique coords types for the dropdown
$coordsTypeList = array_keys($coordsTypeSet);

// Send to client as JSON
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>FCC Towers — Lines between towers (color by kV) — Enhanced</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- MarkerCluster -->
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

<!-- Our styles (external) -->
<link rel="stylesheet" href="style.css" />

</head>
<body>

<div id="map"></div>

<!-- Location indicator -->
<div id="locationIndicator" class="location-indicator">
  <div id="locationStatus">Accessing your location...</div>
  <div id="nearbyTowersCount" class="small"></div>
</div>

<!-- Right-side panel (legend + indicators + filters) -->
<div class="right-panel" id="rightPanel">
  <div class="panel-title">Towers & Transmission Lines — Enhanced</div>
  <div class="stat">Total towers in file: <strong id="statFileTowers"><?php echo $totalFeatures;?></strong></div>
  <div class="stat">Towers shown: <strong id="statTowers">0</strong></div>
  <div class="stat">Total lines drawn: <strong id="statLines">0</strong></div>
  <div class="stat">Towers near you: <strong id="statNearbyTowers">0</strong></div>

  <hr>
  <div style="font-weight:600; margin-bottom:6px;">Voltage Legend (kV)</div>
  <div class="legend-row"><div class="legend-color" style="background:#ff0000"></div><div class="small">765+ kV</div></div>
  <div class="legend-row"><div class="legend-color" style="background:#ff8000"></div><div class="small">500 kV</div></div>
  <div class="legend-row"><div class="legend-color" style="background:#ffff00"></div><div class="small">345 kV</div></div>
  <div class="legend-row"><div class="legend-color" style="background:#00cc66"></div><div class="small">230 kV</div></div>
  <div class="legend-row"><div class="legend-color" style="background:#0080ff"></div><div class="small">138 kV</div></div>
  <div class="legend-row"><div class="legend-color" style="background:#8000ff"></div><div class="small">69 kV</div></div>
  <div class="legend-row"><div class="legend-color" style="background:#999999"></div><div class="small">Unknown/Default</div></div>

  <hr>
  <div style="font-weight:600; margin-bottom:6px;">View & Layers</div>
  <div style="display:flex; gap:8px; margin-bottom:8px;">
    <button id="btnStreet" class="layer-btn active">Street</button>
    <button id="btnSatellite" class="layer-btn">Satellite</button>
  </div>

  <div style="font-weight:600; margin-bottom:6px;">Filters</div>
  <label class="small">CoordsType</label>
  <select id="coordsTypeSelect" style="width:100%; padding:6px; border-radius:6px; margin-bottom:8px;">
    <option value="__all__">All</option>
    <?php foreach ($coordsTypeList as $ct): ?>
      <option value="<?php echo htmlspecialchars($ct, ENT_QUOTES); ?>"><?php echo htmlspecialchars($ct); ?></option>
    <?php endforeach; ?>
  </select>

  <label class="small">Search location (city/address)</label>
  <div style="display:flex; gap:6px; margin-top:6px;">
    <input id="searchInput" type="text" placeholder="Enter location to search" style="flex:1;padding:6px;border-radius:6px;border:1px solid #ddd;" />
    <button id="btnSearch" style="padding:6px 8px;border-radius:6px;border:0;background:#2ecc71;color:white;cursor:pointer;">Go</button>
  </div>
  <div class="small" style="margin-top:6px;">When search completes, map zooms to area and towers within radius are highlighted.</div>

  <hr>
  <div style="font-weight:600; margin-bottom:6px;">Location Controls</div>
  <div class="small">Default radius: <strong>25 km</strong> from your location</div>
  <div style="margin-top:8px; display:flex; gap:8px;">
    <button id="btnFindMe" style="flex:1;padding:8px;border-radius:6px;background:#9b59b6;color:white;border:0;cursor:pointer;">Find towers near me</button>
    <button id="btnClearLocation" style="flex:1;padding:8px;border-radius:6px;background:#e74c3c;color:white;border:0;cursor:pointer;">Clear location</button>
  </div>

  <hr>
  <div style="font-weight:600; margin-bottom:6px;">Other Controls</div>
  <div class="small">Max towers processed for linking (client): <strong id="maxTowersLabel">--</strong></div>
  <div style="margin-top:8px; display:flex; gap:8px;">
    <button id="btnRebuild" style="flex:1;padding:8px;border-radius:6px;background:#3498db;color:white;border:0;cursor:pointer;">Rebuild lines</button>
    <button id="btnClearHighlights" style="flex:1;padding:8px;border-radius:6px;background:#e67e22;color:white;border:0;cursor:pointer;">Clear highlights</button>
  </div>
  <div style="margin-top:12px;" class="small">Tip: increase <code>MAX_TOWERS</code> in the source for more connections if your machine can handle it.</div>
</div>

<!-- Floating tower icon that follows nearest tower (replaces cursor near towers) -->
<img id="floatingTower" class="floating-tower" src="https://static.vecteezy.com/system/resources/previews/009/469/214/non_2x/transmission-tower-icon-style-vector.jpg" alt="tower">

<script>
// == Client-side data from PHP ==
const towers = <?php echo json_encode($towers, JSON_NUMERIC_CHECK); ?>;
const TOTAL_TOWERS = towers.length;
document.getElementById('statTowers').textContent = TOTAL_TOWERS;

// ============== Config ==============
const MAX_TOWERS = Math.min(1400, Math.max(800, TOTAL_TOWERS));
const DEFAULT_RADIUS_KM = 25; // Default radius for showing nearby towers
document.getElementById('maxTowersLabel').textContent = MAX_TOWERS;

// Color palette by voltage (brighter + high contrast)
function getVoltageColor(kv) {
  if (kv === null || kv === undefined || isNaN(kv)) return '#777777';
  kv = Number(kv);
  if (kv >= 765) return '#e60000';
  if (kv >= 500) return '#ff6f00';
  if (kv >= 345) return '#ffd400';
  if (kv >= 230) return '#00b04f';
  if (kv >= 138) return '#0077d6';
  if (kv >= 69) return '#6f00b8';
  return '#777777';
}

// ================= Map init =================
const map = L.map('map', {preferCanvas: true}).setView([39.5, -98.3], 5);

// Base layers
const streetTiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
  maxZoom: 19,
  attribution: '© OpenStreetMap'
}).addTo(map);
const satelliteTiles = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',{
  maxZoom: 19,
  attribution: 'Tiles © Esri'
});

// Marker cluster
const cluster = L.markerClusterGroup({ disableClusteringAtZoom: 13, chunkedLoading: true });
map.addLayer(cluster);

// Pre-create a tower icon
const towerIcon = L.icon({
  iconUrl: 'https://static.vecteezy.com/system/resources/previews/009/469/214/non_2x/transmission-tower-icon-style-vector.jpg',
  iconSize: [28,28],
  iconAnchor: [14,28]
});

// Convert towers into map markers (but only add up to MAX_TOWERS to cluster to keep memory OK)
const markers = []; // store for later use
const idToIndex = new Map();

const useCount = Math.min(MAX_TOWERS, towers.length);
for (let i=0; i<useCount; i++){
  const t = towers[i];
  const lat = Number(t.lat);
  const lon = Number(t.lon);
  const props = t.props || {};
  const displayHeight = t.height !== null && typeof t.height !== 'undefined' ? t.height : (props.Strucht ?? props.OVERALL_HEIGHT ?? props.height ?? 'N/A');
  const displayWeight = t.weight !== null && typeof t.weight !== 'undefined' ? t.weight : (props.Weight ?? props.GROUND_ELEVATION ?? 'N/A');

  const marker = L.marker([lat, lon], { icon: towerIcon, title: (props.Entity ?? props.ContName ?? 'Tower') });
  // tooltip on hover (sticky) - show brief info
  marker.bindTooltip(
    `<div style="font-weight:600;">Tower</div>
     <div class="small">Lat: ${lat.toFixed(6)}<br>Lon: ${lon.toFixed(6)}</div>
     <div class="small">Height: ${displayHeight} | Weight: ${displayWeight}</div>`,
    { sticky: true, className: 'tower-tooltip' }
  );
  // click popup with full details
  marker.on('click', () => {
    const propsHtml = Object.entries(props).slice(0,30).map(([k,v]) => `<div style="font-size:13px;"><strong>${k}:</strong> ${String(v)}</div>`).join('');
    L.popup()
     .setLatLng([lat,lon])
     .setContent(`<div style="max-height:300px; overflow:auto;"><h3>Tower Details</h3>
       <div><strong>Lat:</strong> ${lat.toFixed(6)} <strong>Lon:</strong> ${lon.toFixed(6)}</div>
       <div><strong>Height:</strong> ${displayHeight}</div>
       <div><strong>Weight:</strong> ${displayWeight}</div>
       <hr>${propsHtml}</div>`)
     .openOn(map);
  });

  markers.push(marker);
  cluster.addLayer(marker);
  idToIndex.set(`${lat},${lon}`, i);
}

// Helper: haversine distance (km)
function haversineKm(lat1, lon1, lat2, lon2){
  const R = 6371;
  const dLat = (lat2-lat1)*Math.PI/180;
  const dLon = (lon2-lon1)*Math.PI/180;
  const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)**2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

// ================= Build lines (connect each tower to nearest east & west) =================
let lineLayerGroup = L.layerGroup().addTo(map);
let glowLayerGroup = L.layerGroup().addTo(map); // extra layer to create glow/thicker base for clearer visibility
let linesCount = 0;

function extractVoltageFromProps(p){
  if (!p) return null;
  const keys = Object.keys(p);
  for (const k of keys){
    const kl = k.toLowerCase();
    if (kl.includes('volt') || kl.includes('kv')){
      const val = String(p[k]);
      const match = val.match(/(\d{2,4})/);
      if (match) return Number(match[1]);
    }
  }
  return null;
}

function buildLines(coordsTypeFilter=null){
  lineLayerGroup.clearLayers();
  glowLayerGroup.clearLayers();
  const linesSeen = new Set(); // dedupe by key
  linesCount = 0;

  // pre-extract lat/lon for speed + filter by coordsType if provided
  const coords = [];
  for (let i=0;i<useCount;i++){
    const t = towers[i];
    if (coordsTypeFilter && coordsTypeFilter !== '__all__'){
      // skip if not matching (some entries may have null coordsType)
      const c = (t.coordsType || '').toString();
      if (c !== coordsTypeFilter) continue;
    }
    coords.push([Number(t.lat), Number(t.lon), t.props || {}]);
  }

  for (let i=0;i<coords.length;i++){
    const [lat, lon, props] = coords[i];

    let nearestEast = null, nearestEastDist = Infinity;
    let nearestWest = null, nearestWestDist = Infinity;

    // naive O(n^2) nearest search (works for ~thousands; if you need speed, implement KD-tree)
    for (let j=0;j<coords.length;j++){
      if (i===j) continue;
      const [lat2, lon2, props2] = coords[j];

      // East = lon2 > lon
      if (lon2 > lon){
        const d = haversineKm(lat, lon, lat2, lon2);
        if (d < nearestEastDist){
          nearestEastDist = d; nearestEast = {lat:lat2, lon:lon2, props: props2};
        }
      }
      // West = lon2 < lon
      if (lon2 < lon){
        const d = haversineKm(lat, lon, lat2, lon2);
        if (d < nearestWestDist){
          nearestWestDist = d; nearestWest = {lat:lat2, lon:lon2, props: props2};
        }
      }
    }

    // Create lines to east and west if found
    const neighbors = [
      {dir:'east', item:nearestEast, dist:nearestEastDist},
      {dir:'west', item:nearestWest, dist:nearestWestDist}
    ];

    for (const n of neighbors){
      if (!n.item) continue;
      const kv1 = extractVoltageFromProps(props);
      const kv2 = extractVoltageFromProps(n.item.props);
      let kv = kv1 || kv2 || null;
      if (kv1 && kv2) kv = Math.max(kv1, kv2);

      const color = getVoltageColor(kv);
      const weight = kv ? Math.min(8, Math.max(3, kv / 180)) : 3; // slightly thicker

      // //////////////////////////////////////////////////////
      // Glow effect: draw an underlying polyline with larger weight & lower opacity
      // Then draw the main polyline on top with sharper color. This improves visibility.
      // //////////////////////////////////////////////////////

      const aKey = `${lat.toFixed(8)},${lon.toFixed(8)}`;
      const bKey = `${n.item.lat.toFixed(8)},${n.item.lon.toFixed(8)}`;
      const key = aKey < bKey ? `${aKey}|${bKey}` : `${bKey}|${aKey}`;
      if (linesSeen.has(key)) continue;
      linesSeen.add(key);

      const latlngs = [[lat,lon],[n.item.lat, n.item.lon]];

      // glow polyline
      const glow = L.polyline(latlngs, { color: color, weight: weight + 6, opacity: 0.18, interactive: false, className: 'line-glow' });
      glow.addTo(glowLayerGroup);

      // main polyline
      const poly = L.polyline(latlngs, { color: color, weight: weight, opacity: 0.95, interactive: true, className: 'line-main' });

      // attach tooltip on hover with length & kv info & direction
      const lengthKm = haversineKm(lat,lon, n.item.lat, n.item.lon);
      const lengthStr = (lengthKm >= 1) ? (lengthKm.toFixed(2) + ' km') : (Math.round(lengthKm*1000) + ' m');

      poly.bindTooltip(
        `<div class="line-tooltip"><div style="font-weight:600">Transmission-like link</div>
         <div style="font-size:13px;">Length: ${lengthStr}</div>
         <div style="font-size:13px;">Voltage: ${kv ? kv + ' kV' : 'N/A'}</div>
         <div style="font-size:12px;color:#666;">(${n.dir.toUpperCase()})</div></div>`,
        { sticky: true, className: 'line-tooltip' }
      );

      poly.on('mouseover', function(){ this.setStyle({ opacity:1, weight: weight+2 }); });
      poly.on('mouseout', function(){ this.setStyle({ opacity:0.95, weight: weight }); });

      poly.addTo(lineLayerGroup);
      linesCount++;
    }
  } // end for each tower

  document.getElementById('statLines').textContent = linesCount;
  console.log('Built lines:', linesCount);
}

// initial build (show all coords types)
buildLines('__all__');

// Rebuild on button click (in case user wants)
document.getElementById('btnRebuild').addEventListener('click', function(){
  const sel = document.getElementById('coordsTypeSelect').value;
  buildLines(sel);
});

// coordsType dropdown changes
document.getElementById('coordsTypeSelect').addEventListener('change', function(){
  const sel = this.value;
  // rebuild lines using filter and also re-render markers visibility
  filterMarkersByCoordsType(sel);
  buildLines(sel);
});

// filter markers by coordsType
function filterMarkersByCoordsType(coordsType){
  cluster.clearLayers();
  for (let i=0;i<markers.length;i++){
    const m = markers[i];
    const t = towers[i];
    if (coordsType && coordsType !== '__all__'){
      const c = (t.coordsType || '').toString();
      if (c !== coordsType) continue; // skip
    }
    cluster.addLayer(m);
  }
}

// ============ Floating tower icon that follows nearest marker when mouse near =============
const floating = document.getElementById('floatingTower');
const floatRadiusPx = 24; // distance in pixels to consider "near" a tower marker
let lastNearestIndex = -1;

map.getContainer().addEventListener('mousemove', function(ev){
  const rect = map.getBoundingClientRect();
  const pt = L.point(ev.clientX - rect.left, ev.clientY - rect.top);
  // find nearest marker (screen distance) among markers (use marker.getLatLng->containerPoint)
  let nearest = null;
  let nearestDist = Infinity;
  const markerList = markers; // markers corresponds to first useCount towers
  for (let i=0;i<markerList.length;i++){
    const m = markerList[i];
    const p = map.latLngToContainerPoint(m.getLatLng());
    const dx = p.x - pt.x;
    const dy = p.y - pt.y;
    const d = Math.sqrt(dx*dx + dy*dy);
    if (d < nearestDist) { nearestDist = d; nearest = { index:i, marker:m, point:p }; }
  }
  if (nearest && nearestDist <= floatRadiusPx + 6){
    // show floating tower at that marker screen position
    floating.style.left = (nearest.point.x + rect.left) + 'px';
    floating.style.top = (nearest.point.y + rect.top) + 'px';
    floating.style.display = 'block';
    lastNearestIndex = nearest.index;
  } else {
    floating.style.display = 'none';
    lastNearestIndex = -1;
  }
});

// hide floating icon when mouse leaves map container
map.getContainer().addEventListener('mouseleave', ()=> floating.style.display='none');

// ============ Location-based tower highlighting =============
let userLocation = null;
let userMarker = null;
let locationCircle = null;
let nearbyTowersLayer = L.layerGroup().addTo(map);
let highlightLayer = L.layerGroup().addTo(map);

// Function to find and highlight towers near user location
function highlightTowersNearUser(userLat, userLon, radiusKm = DEFAULT_RADIUS_KM) {
  // Clear previous nearby towers
  nearbyTowersLayer.clearLayers();
  
  // Add user location marker
  if (userMarker) {
    map.removeLayer(userMarker);
  }
  userMarker = L.marker([userLat, userLon], {
    icon: L.icon({
      iconUrl: 'https://cdn-icons-png.flaticon.com/512/684/684908.png',
      iconSize: [32, 32],
      iconAnchor: [16, 32]
    })
  }).addTo(nearbyTowersLayer);
  
  // Add circle to show radius
  if (locationCircle) {
    map.removeLayer(locationCircle);
  }
  locationCircle = L.circle([userLat, userLon], {
    radius: radiusKm * 1000,
    color: '#3498db',
    fillColor: '#3498db',
    fillOpacity: 0.1,
    weight: 2
  }).addTo(nearbyTowersLayer);
  
  // Find towers within radius
  let nearbyCount = 0;
  const nearbyMarkers = [];
  
  for (let i = 0; i < markers.length; i++) {
    const marker = markers[i];
    const ll = marker.getLatLng();
    const distance = haversineKm(userLat, userLon, ll.lat, ll.lng);
    
    if (distance <= radiusKm) {
      nearbyCount++;
      // Create a highlighted marker for nearby towers
      const nearbyMarker = L.circleMarker([ll.lat, ll.lng], {
        radius: 10,
        color: '#e74c3c',
        fillColor: '#e74c3c',
        fillOpacity: 0.8,
        weight: 2
      }).addTo(nearbyTowersLayer);
      
      // Copy the tooltip from original marker
      const tooltipContent = marker.getTooltip()?.getContent();
      if (tooltipContent) {
        nearbyMarker.bindTooltip(tooltipContent, { sticky: true });
      }
      
      // Add click event to show tower details
      nearbyMarker.on('click', () => {
        marker.fire('click');
      });
      
      nearbyMarkers.push(nearbyMarker);
      
      // Add distance to tooltip
      const distanceStr = distance < 1 ? 
        `${(distance * 1000).toFixed(0)} m` : 
        `${distance.toFixed(2)} km`;
      
      nearbyMarker.bindTooltip(`
        <div style="font-weight:600;">Tower (${distanceStr} away)</div>
        <div class="small">Lat: ${ll.lat.toFixed(6)}<br>Lon: ${ll.lng.toFixed(6)}</div>
        <div class="small">Distance from you: ${distanceStr}</div>
      `, { sticky: true });
    }
  }
  
  // Update statistics
  document.getElementById('statNearbyTowers').textContent = nearbyCount;
  document.getElementById('nearbyTowersCount').textContent = `Found ${nearbyCount} towers within ${radiusKm} km`;
  
  // Show location indicator
  document.getElementById('locationIndicator').style.display = 'block';
  document.getElementById('locationStatus').textContent = 
    `Your location: ${userLat.toFixed(5)}, ${userLon.toFixed(5)}`;
  
  // Zoom to show both user location and nearby towers
  const bounds = L.latLngBounds([[userLat, userLon]]);
  nearbyMarkers.forEach(m => bounds.extend(m.getLatLng()));
  map.fitBounds(bounds.pad(0.1), { maxZoom: 12 });
  
  return nearbyCount;
}

// Function to get user's location
function getUserLocation() {
  const locationIndicator = document.getElementById('locationIndicator');
  const locationStatus = document.getElementById('locationStatus');
  
  if (!navigator.geolocation) {
    locationStatus.textContent = 'Geolocation is not supported by your browser';
    return;
  }
  
  locationIndicator.style.display = 'block';
  locationStatus.textContent = 'Accessing your location...';
  
  navigator.geolocation.getCurrentPosition(
    function(position) {
      const userLat = position.coords.latitude;
      const userLon = position.coords.longitude;
      userLocation = { lat: userLat, lon: userLon };
      
      // Highlight towers near user
      const count = highlightTowersNearUser(userLat, userLon);
      
      locationStatus.textContent = `Found ${count} towers near you`;
      locationIndicator.style.display = 'block';
    },
    function(error) {
      let message = 'Unable to retrieve your location';
      switch(error.code) {
        case error.PERMISSION_DENIED:
          message = 'Location permission denied. Please allow location access.';
          break;
        case error.POSITION_UNAVAILABLE:
          message = 'Location information unavailable.';
          break;
        case error.TIMEOUT:
          message = 'Location request timed out.';
          break;
      }
      locationStatus.textContent = message;
      // Hide indicator after 5 seconds if there's an error
      setTimeout(() => {
        locationIndicator.style.display = 'none';
      }, 5000);
    },
    {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 0
    }
  );
}

// Function to clear location highlights
function clearLocationHighlights() {
  if (userMarker) {
    map.removeLayer(userMarker);
    userMarker = null;
  }
  if (locationCircle) {
    map.removeLayer(locationCircle);
    locationCircle = null;
  }
  nearbyTowersLayer.clearLayers();
  userLocation = null;
  
  document.getElementById('statNearbyTowers').textContent = '0';
  document.getElementById('locationIndicator').style.display = 'none';
}

// ============ Event Listeners for Location Controls =============
document.getElementById('btnFindMe').addEventListener('click', getUserLocation);
document.getElementById('btnClearLocation').addEventListener('click', clearLocationHighlights);

// ============ Search (Nominatim) and highlight towers in area =============
async function geocode(query){
  // using Nominatim (public) - respect usage policy for heavy use
  const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=5`;
  try {
    const res = await fetch(url, { headers: { 'Accept-Language': 'en' } });
    if (!res.ok) return null;
    const j = await res.json();
    return j; // array
  } catch (e){
    console.error('Geocode error', e);
    return null;
  }
}

document.getElementById('btnSearch').addEventListener('click', async ()=>{
  const q = document.getElementById('searchInput').value.trim();
  if (!q) return alert('Please type a location to search');
  const results = await geocode(q);
  if (!results || results.length===0) return alert('No results found');
  const r = results[0];
  const lat = Number(r.lat); const lon = Number(r.lon);
  const bounds = [ [lat-0.05, lon-0.05], [lat+0.05, lon+0.05] ];
  map.setView([lat, lon], 12);

  // determine radius (in km) based on bounding box approx — choose 15km radius by default
  const radiusKm = 15;

  highlightLayer.clearLayers();

  const circle = L.circle([lat, lon], { radius: radiusKm * 1000, color: '#ff3333', fillOpacity: 0.03 }).addTo(highlightLayer);

  // find markers within radius and highlight them — also open their popups briefly
  let found = 0;
  for (let i=0;i<markers.length;i++){
    const m = markers[i];
    const ll = m.getLatLng();
    const d = haversineKm(lat, lon, ll.lat, ll.lng);
    if (d <= radiusKm){
      found++;
      const h = L.circleMarker([ll.lat, ll.lng], { radius:8, color:'#ff3333', fill:true, fillOpacity:0.9 }).addTo(highlightLayer);
      // optional: show popup with brief props
      // L.popup().setLatLng([ll.lat, ll.lng]).setContent('<div>Nearby tower</div>').openOn(map);
    }
  }
  alert(`${found} towers found within ${radiusKm} km of ${r.display_name}`);
});

// clear highlights
document.getElementById('btnClearHighlights').addEventListener('click', ()=>{
  highlightLayer.clearLayers();
});

// ============ Layer toggle (street / satellite) =============
document.getElementById('btnStreet').addEventListener('click', ()=>{
  if (!map.hasLayer(streetTiles)) map.addLayer(streetTiles);
  if (map.hasLayer(satelliteTiles)) map.removeLayer(satelliteTiles);
  document.getElementById('btnStreet').classList.add('active');
  document.getElementById('btnSatellite').classList.remove('active');
});

document.getElementById('btnSatellite').addEventListener('click', ()=>{
  if (!map.hasLayer(satelliteTiles)) map.addLayer(satelliteTiles);
  if (map.hasLayer(streetTiles)) map.removeLayer(streetTiles);
  document.getElementById('btnSatellite').classList.add('active');
  document.getElementById('btnStreet').classList.remove('active');
});

// ============ Initial filter state =============
filterMarkersByCoordsType('__all__');

// ============ Auto-get user location on page load =============
// Ask for permission and get location when page loads
window.addEventListener('load', function() {
  // Show a brief message that we'll try to access location
  setTimeout(() => {
    getUserLocation();
  }, 1000); // Wait 1 second for page to fully load
});

</script>
</body>
</html>