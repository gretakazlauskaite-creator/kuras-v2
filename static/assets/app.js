(() => {
  'use strict';
  const embedded = new URLSearchParams(window.location.search).get('embed') === '1';
  if (embedded) document.documentElement.classList.add('embedded');
  const fuelLabels = {pb95:'Pb 95',pb98:'Pb 98',diesel:'Dyzelinas',lpg:'Dujos'};
  const lithuaniaBounds = [[53.85,20.90],[56.45,26.85]];
  const state = {data:null,fuel:'pb95',page:1,perPage:15,lat:null,lng:null,accuracy:null,map:null,tileLayer:null,tileFailures:0,usingFallbackTiles:false,markers:null,userLayers:null,markerByStationId:new Map(),mapResizeObserver:null,mapResizeFrame:null,focusLocation:false,focusStationId:null,selectedStationId:null,fitResults:false};
  const $ = selector => document.querySelector(selector);
  const $$ = selector => [...document.querySelectorAll(selector)];
  const euro = value => value == null ? '—' : Number(value).toFixed(3).replace('.',',')+' €';
  const integer = value => new Intl.NumberFormat('lt-LT').format(value || 0);
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const value = selector => $(selector).value.trim();
  const hasCoordinates = station => station?.latitude!=null&&station?.longitude!=null&&Number.isFinite(Number(station.latitude))&&Number.isFinite(Number(station.longitude));
  const mapsSearchUrl = station => {
    const query=[station?.name||station?.brand,station?.address,station?.city,station?.municipality,'Lietuva'].filter(Boolean).join(', ');
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`;
  };

  function distance(aLat,aLng,bLat,bLng){const r=6371,dLat=(bLat-aLat)*Math.PI/180,dLng=(bLng-aLng)*Math.PI/180;const a=Math.sin(dLat/2)**2+Math.cos(aLat*Math.PI/180)*Math.cos(bLat*Math.PI/180)*Math.sin(dLng/2)**2;return r*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));}
  function fuelStations(){
    if(!state.data)return [];
    const query=value('[data-search]').toLocaleLowerCase('lt');
    return state.data.stations.filter(s => s.prices?.[state.fuel] != null)
      .filter(s => !value('[data-city]') || s.city===value('[data-city]'))
      .filter(s => !value('[data-brand]') || s.brand===value('[data-brand]'))
      .filter(s => !query || [s.name,s.brand,s.address,s.city,s.municipality].join(' ').toLocaleLowerCase('lt').includes(query))
      .map(s => ({...s,distance_km:state.lat!=null&&hasCoordinates(s)?distance(state.lat,state.lng,Number(s.latitude),Number(s.longitude)):null}))
      .sort((a,b) => {const mode=value('[data-sort]');if(mode==='price-desc')return b.prices[state.fuel]-a.prices[state.fuel];if(mode==='name')return a.brand.localeCompare(b.brand,'lt');if(mode==='distance')return ((a.distance_km??Infinity)-(b.distance_km??Infinity))||(a.prices[state.fuel]-b.prices[state.fuel]);return a.prices[state.fuel]-b.prices[state.fuel];});
  }
  function options(selector,items,first){$(selector).innerHTML=`<option value="">${first}</option>`+[...items].sort((a,b)=>a.localeCompare(b,'lt')).map(item=>`<option>${escapeHtml(item)}</option>`).join('');}
  function renderSource(){const s=state.data.source,node=$('[data-source]');node.classList.toggle('demo',Boolean(state.data.demo));const generated=new Date(s.generated_at);const time=Number.isNaN(generated.getTime())?s.generated_at:new Intl.DateTimeFormat('lt-LT',{dateStyle:'short',timeStyle:'short',timeZone:'Europe/Vilnius'}).format(generated);node.lastChild.textContent=` ${state.data.demo?'Demonstraciniai duomenys':'Naujausia LEA data: '+s.source_date} · patikrinta ${time}`;if(state.data.demo){const n=$('[data-notice]');n.hidden=false;n.className='notice info';n.textContent='Kol įjungsime kasdienį atnaujinimą, rodoma saugi demonstracinė duomenų kopija.';}}
  function renderTabs(){const fuels=state.data.summary.fuels.filter(f=>fuelLabels[f]);if(!fuels.includes(state.fuel))state.fuel=fuels[0];$('[data-fuels]').innerHTML=fuels.map(f=>`<button type="button" class="${f===state.fuel?'active':''}" data-fuel="${f}">${fuelLabels[f]} <small>${integer(state.data.stations.filter(s=>s.prices?.[f]!=null).length)}</small></button>`).join('');document.querySelectorAll('[data-fuel]').forEach(b=>b.onclick=()=>{state.fuel=b.dataset.fuel;state.page=1;renderAll();});}
  function renderSummary(rows){const prices=rows.map(s=>Number(s.prices[state.fuel]));$('[data-average]').textContent=euro(prices.length?prices.reduce((a,b)=>a+b,0)/prices.length:null);$('[data-minimum]').textContent=euro(prices.length?Math.min(...prices):null);$('[data-count]').textContent=integer(rows.length);}
  function renderTop(rows){const nearby=value('[data-sort]')==='distance'&&state.lat!=null;$('[data-top-kicker]').textContent=nearby?'Iš degalinių su patikrinta vieta':'Pigiausi pagal pasirinktus filtrus';$('[data-top-title]').textContent=nearby?'Artimiausios degalinės':'Degalinių TOP 3';$('[data-top-fuel]').textContent=fuelLabels[state.fuel];$('[data-top]').innerHTML=rows.slice(0,3).map((s,i)=>`<article class="top-card"><span class="rank">${i+1}</span><div class="station-copy"><strong>${escapeHtml(s.brand)}</strong><small>${escapeHtml(s.address)}${s.city?', '+escapeHtml(s.city):''}${s.distance_km!=null?' · '+s.distance_km.toFixed(1)+' km':''}</small></div><span class="price">${euro(s.prices[state.fuel])}</span></article>`).join('')||'<p class="empty">Pagal pasirinktus filtrus kainų nerasta.</p>';}
  function renderTable(rows){
    const pages=Math.max(1,Math.ceil(rows.length/state.perPage));
    state.page=Math.min(state.page,pages);
    const shown=rows.slice((state.page-1)*state.perPage,state.page*state.perPage);
    $('[data-results-count]').textContent=`Rasta ${integer(rows.length)}`;
    $('[data-page]').textContent=`${state.page} iš ${pages}`;
    $('[data-prev]').disabled=state.page<=1;
    $('[data-next]').disabled=state.page>=pages;
    $('[data-table]').innerHTML=shown.map(s=>{
      const id=String(s.id);
      const selected=id===state.selectedStationId;
      const mapped=hasCoordinates(s);
      const label=mapped?`Rodyti degalinę ${s.name||s.brand} žemėlapyje`:`Ieškoti degalinės ${s.name||s.brand} žemėlapyje pagal adresą`;
      const mapStatus=mapped?'':`<span class="map-status">Vieta tikslinama · atidaryti pagal adresą <span aria-hidden="true">↗</span></span>`;
      return `<tr class="station-row${selected?' is-selected':''}${mapped?'':' needs-map'}" data-station-id="${escapeHtml(id)}" tabindex="0" role="button" aria-label="${escapeHtml(label)}" aria-pressed="${selected?'true':'false'}"><td><span class="station-name">${escapeHtml(s.name||s.brand)}</span><span class="brand">${escapeHtml(s.brand)}</span></td><td><span class="address">${escapeHtml(s.address)}<br>${escapeHtml(s.city||s.municipality||'')}${s.distance_km!=null?' · '+s.distance_km.toFixed(1)+' km':''}</span>${mapStatus}</td><td><strong class="price">${euro(s.prices[state.fuel])}</strong><span class="brand">už litrą</span></td></tr>`;
    }).join('')||'<tr><td colspan="3" class="empty">Pagal pasirinktus filtrus degalinių nerasta.</td></tr>';
    $$('[data-table] [data-station-id]').forEach(row=>{
      const select=()=>selectStation(row.dataset.stationId);
      row.onclick=select;
      row.onkeydown=event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();select();}};
    });
  }
  function syncMap(resetToLithuania=false,redrawTiles=false){if(!state.map)return;if(state.mapResizeFrame)cancelAnimationFrame(state.mapResizeFrame);state.mapResizeFrame=requestAnimationFrame(()=>{state.map.invalidateSize({animate:false,pan:false});if(resetToLithuania&&state.lat==null)state.map.fitBounds(lithuaniaBounds,{padding:[12,12]});if(redrawTiles&&state.tileLayer)state.tileLayer.redraw();});}
  function addTileLayer(url){const layer=L.tileLayer(url,{minZoom:6,maxZoom:18,keepBuffer:3,updateWhenIdle:false,crossOrigin:true,attribution:'© OpenStreetMap contributors'});layer.on('tileerror',()=>{state.tileFailures++;if(state.tileFailures<3||state.usingFallbackTiles)return;state.usingFallbackTiles=true;state.tileFailures=0;state.map.removeLayer(layer);state.tileLayer=addTileLayer('https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png');setTimeout(()=>syncMap(true,true),80);});layer.addTo(state.map);return layer;}
  function initMap(){if(state.map||!window.L)return;const node=$('[data-map]');if(!node||node.offsetWidth===0||node.offsetHeight===0)return;state.map=L.map(node,{minZoom:6,preferCanvas:true}).setView([55.17,23.88],7);state.tileLayer=addTileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png');state.markers=L.layerGroup().addTo(state.map);state.userLayers=L.layerGroup().addTo(state.map);if('ResizeObserver' in window){state.mapResizeObserver=new ResizeObserver(()=>syncMap(state.lat==null));state.mapResizeObserver.observe(node);}window.addEventListener('resize',()=>syncMap(state.lat==null));syncMap(true);[150,500,1200].forEach((delay,index)=>setTimeout(()=>syncMap(true,index===2),delay));}
  function popupHtml(s){const address=String(s.address||'');const city=s.city&&!address.toLocaleLowerCase('lt').includes(String(s.city).toLocaleLowerCase('lt'))?`<span>${escapeHtml(s.city)}</span>`:'';const distance=s.distance_km!=null?`<span class="station-popup-distance">${s.distance_km.toFixed(1)} km nuo jūsų</span>`:'';const route=`https://www.google.com/maps/dir/?api=1&destination=${Number(s.latitude)},${Number(s.longitude)}`;return `<article class="station-popup"><span class="station-popup-label">${escapeHtml(fuelLabels[state.fuel])}</span><strong class="station-popup-name">${escapeHtml(s.brand)}</strong><p>${escapeHtml(address)}${city}</p><div class="station-popup-footer"><div><small>Kaina už litrą</small><b>${euro(s.prices[state.fuel])}</b>${distance}</div><a href="${route}" target="_blank" rel="noopener">Maršrutas <span aria-hidden="true">↗</span></a></div></article>`;}
  function renderMap(rows){
    initMap();
    if(!state.map)return;
    state.markers.clearLayers();
    state.userLayers.clearLayers();
    state.markerByStationId.clear();
    const mapped=rows.filter(hasCoordinates);
    const emphasized=new Set((state.lat!=null?mapped.slice(0,10):[...mapped].sort((a,b)=>a.prices[state.fuel]-b.prices[state.fuel]).slice(0,12)).map(s=>String(s.id)));
    mapped.forEach(s=>{
      const id=String(s.id);
      const selected=id===state.selectedStationId;
      const popup=popupHtml(s);
      const popupOptions={minWidth:240,maxWidth:290,offset:[0,-3],autoPanPaddingTopLeft:[58,20],autoPanPaddingBottomRight:[20,20]};
      let marker;
      if(emphasized.has(id)||selected){
        const markerClass=selected?'selected':state.lat!=null?'nearby':'cheap';
        marker=L.marker([Number(s.latitude),Number(s.longitude)],{icon:L.divIcon({className:'price-marker',html:`<span class="marker ${markerClass}">${euro(s.prices[state.fuel])}</span>`,iconSize:[76,30],iconAnchor:[38,15]})}).bindPopup(popup,popupOptions).addTo(state.markers);
      }else{
        marker=L.circleMarker([Number(s.latitude),Number(s.longitude)],{radius:5,color:'#fff',weight:1.5,fillColor:'#326f94',fillOpacity:.82}).bindPopup(popup,popupOptions).addTo(state.markers);
      }
      state.markerByStationId.set(id,marker);
    });
    if(state.lat!=null){
      if(state.accuracy)L.circle([state.lat,state.lng],{radius:Math.min(state.accuracy,2000),color:'#326f94',weight:1,fillColor:'#326f94',fillOpacity:.08,interactive:false}).addTo(state.userLayers);
      L.circleMarker([state.lat,state.lng],{radius:9,color:'#fff',weight:3,fillColor:'#326f94',fillOpacity:1}).bindTooltip('Jūsų vieta',{permanent:true,direction:'top',offset:[0,-10]}).addTo(state.userLayers);
    }
    $('[data-map-legend]').textContent=state.lat!=null?'Mėlyna kaina – viena artimiausių':'Žalia kaina – viena pigiausių';
    $('[data-map-legend-dot]').classList.toggle('nearby',state.lat!=null);
    const selected=mapped.find(s=>String(s.id)===state.selectedStationId);
    $('[data-map-note]').textContent=!mapped.length?'Kainos jau veikia. Tikslios degalinių koordinatės bus pridėtos kitame etape.':selected?`Pasirinkta: ${selected.name||selected.brand}, ${selected.address}.`:state.lat!=null?`Pirmiausia rodomos artimiausios iš ${integer(mapped.length)} degalinių su patikrinta vieta.`:`Rodomas Lietuvos žemėlapis ir ${integer(mapped.length)} degalinių. Paspauskite degalinę sąraše, kad ją rastumėte žemėlapyje.`;
    setTimeout(()=>{
      syncMap(false);
      if(state.focusStationId){
        const station=mapped.find(s=>String(s.id)===state.focusStationId);
        const marker=state.markerByStationId.get(state.focusStationId);
        if(station&&marker){
          state.map.setView([Number(station.latitude),Number(station.longitude)],14);
          marker.openPopup();
        }
        state.focusStationId=null;
      }else if(state.focusLocation&&state.lat!=null){
        state.map.setView([state.lat,state.lng],11);
        state.focusLocation=false;
      }else if(state.fitResults&&mapped.length){
        state.map.fitBounds(L.latLngBounds(mapped.map(s=>[s.latitude,s.longitude])),{padding:[26,26],maxZoom:12});
        state.fitResults=false;
      }
    },50);
  }
  function showMapView(){
    $$('[data-view-button]').forEach(button=>button.classList.toggle('active',button.dataset.viewButton==='map'));
    $('.results').classList.add('show-map');
  }
  function selectStation(id){
    const station=state.data?.stations.find(item=>String(item.id)===String(id));
    if(!station)return;
    if(!hasCoordinates(station)){
      window.open(mapsSearchUrl(station),'_blank','noopener,noreferrer');
      showNotice('Tiksli šios degalinės vieta dar tikslinama. Atidarėme žemėlapio paiešką pagal oficialų LEA adresą.','info');
      return;
    }
    state.selectedStationId=String(id);
    state.focusStationId=String(id);
    showMapView();
    const rows=fuelStations();
    renderTable(rows);
    requestAnimationFrame(()=>renderMap(rows));
  }
  function renderAll(){renderTabs();const rows=fuelStations();if(state.selectedStationId&&!rows.some(s=>String(s.id)===state.selectedStationId)){state.selectedStationId=null;state.focusStationId=null;}renderSummary(rows);renderTop(rows);renderTable(rows);renderMap(rows);}
  function setLocationButtons({disabled=false,text='Naudoti mano vietą',ready=false,title=''}){$$('[data-locate]').forEach(button=>{button.disabled=disabled;button.textContent=text;button.title=title;button.classList.toggle('is-ready',ready);});}
  function showNotice(message,type='error'){const notice=$('[data-notice]');notice.hidden=false;notice.className=`notice${type==='info'?' info':''}`;notice.textContent=message;}
  function geolocationMessage(error){if(error?.code===1)return 'Vietos leidimas nesuteiktas. Telefono arba naršyklės nustatymuose leiskite šiam puslapiui naudoti vietą.';if(error?.code===2)return 'Įrenginiui nepavyko nustatyti vietos. Patikrinkite, ar telefone įjungta vietos nustatymo funkcija.';if(error?.code===3)return 'Vietos nustatymas užtruko per ilgai. Pabandykite dar kartą vietoje, kur geresnis GPS signalas.';return 'Vietos nustatyti nepavyko. Pabandykite dar kartą.';}
  function locate(){const coordinateCount=state.data?.stations.filter(hasCoordinates).length||0;if(!coordinateCount){showNotice('Artimiausių degalinių skaičiavimas bus įjungtas, kai prie LEA adresų bus prijungtos patikrintos koordinatės.','info');return;}if(!navigator.geolocation){showNotice('Ši naršyklė nepalaiko vietos nustatymo. Galite toliau ieškoti pagal miestą ar adresą.');return;}setLocationButtons({disabled:true,text:'Nustatoma vieta…'});navigator.geolocation.getCurrentPosition(p=>{state.lat=p.coords.latitude;state.lng=p.coords.longitude;state.accuracy=p.coords.accuracy;state.focusLocation=true;$('[data-sort] option[value="distance"]').disabled=false;$('[data-sort]').value='distance';state.page=1;$('[data-notice]').hidden=true;renderAll();setLocationButtons({text:'Vieta nustatyta · atnaujinti',ready:true,title:'Paspauskite dar kartą vietai atnaujinti'});},error=>{setLocationButtons({text:'Bandykite dar kartą'});showNotice(geolocationMessage(error));},{enableHighAccuracy:true,timeout:12000,maximumAge:300000});}
  async function start(){try{if(window.__KURAS_DATA){state.data=window.__KURAS_DATA;}else{const response=await fetch('data/current.json',{cache:'no-store'});if(!response.ok)throw new Error();state.data=await response.json();}options('[data-city]',new Set(state.data.stations.map(s=>s.city).filter(Boolean)),'Visa Lietuva');options('[data-brand]',new Set(state.data.stations.map(s=>s.brand).filter(Boolean)),'Visi tinklai');const coordinateCount=state.data.stations.filter(hasCoordinates).length;$('[data-coordinate-coverage]').textContent=coordinateCount?`${integer(coordinateCount)} iš ${integer(state.data.stations.length)} degalinių turi patikrintą vietą žemėlapyje.`:'Patikrintų degalinių koordinačių dar nėra.';renderSource();renderAll();if(!coordinateCount)setLocationButtons({disabled:true,text:'Artimiausios – ruošiama',title:'Laukiama patikrintų degalinių koordinačių'});}catch(_){showNotice('Kainų failo gauti nepavyko. Automatinis atnaujinimas išsaugojo paskutinę gerą versiją.');}}
  function publishHeight(){if(!embedded||window.parent===window)return;window.parent.postMessage({type:'kuras-pricer:height',height:Math.ceil(document.documentElement.scrollHeight)},'*');}
  if(embedded){if('ResizeObserver' in window)new ResizeObserver(publishHeight).observe(document.body);window.addEventListener('load',publishHeight);}
  $('[data-filters]').onsubmit=e=>{e.preventDefault();state.page=1;state.fitResults=state.lat==null;renderAll();};$('[data-prev]').onclick=()=>{state.page--;renderTable(fuelStations());};$('[data-next]').onclick=()=>{state.page++;renderTable(fuelStations());};$$('[data-locate]').forEach(button=>button.onclick=locate);document.querySelectorAll('[data-view-button]').forEach(b=>b.onclick=()=>{document.querySelectorAll('[data-view-button]').forEach(x=>x.classList.toggle('active',x===b));const showMap=b.dataset.viewButton==='map';$('.results').classList.toggle('show-map',showMap);if(showMap)requestAnimationFrame(()=>renderMap(fuelStations()));else syncMap(false);});start();
})();
