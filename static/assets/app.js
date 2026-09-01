(() => {
  'use strict';
  const embedded = new URLSearchParams(window.location.search).get('embed') === '1';
  if (embedded) document.documentElement.classList.add('embedded');
  const fuelLabels = {pb95:'Pb 95',pb98:'Pb 98',diesel:'Dyzelinas',lpg:'Dujos'};
  const lithuaniaBounds = [[53.85,20.90],[56.45,26.85]];
  const state = {data:null,history:{days:[]},checkedAt:null,fuel:'pb95',page:1,perPage:15,lat:null,lng:null,accuracy:null,map:null,tileLayer:null,tileFailures:0,usingFallbackTiles:false,markers:null,userLayers:null,markerByStationId:new Map(),mapResizeObserver:null,mapResizeFrame:null,focusLocation:false,focusStationId:null,selectedStationId:null,fitResults:false};
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
  const priceValue = (station,fuel=state.fuel) => {
    const raw=station?.prices?.[fuel];
    return raw==null||raw===''||!Number.isFinite(Number(raw))?null:Number(raw);
  };
  const declaresFuel = (station,fuel=state.fuel) => priceValue(station,fuel)!=null||(Array.isArray(station?.unavailable_fuels)&&station.unavailable_fuels.includes(fuel));
  const localTime = timestamp => {
    const date=new Date(timestamp);
    return Number.isNaN(date.getTime())?String(timestamp||'—'):new Intl.DateTimeFormat('lt-LT',{dateStyle:'short',timeStyle:'short',timeZone:'Europe/Vilnius'}).format(date);
  };
  function priceStatus(station){
    const price=priceValue(station);
    if(price==null)return {kind:'missing',label:'Nauja kaina nepateikta'};
    const timestamp=station?.price_updated_at?.[state.fuel];
    if(!timestamp)return {kind:'current',label:''};
    const date=new Date(timestamp);
    if(Number.isNaN(date.getTime()))return {kind:'current',label:''};
    const stale=Date.now()-date.getTime()>36*60*60*1000;
    return {kind:stale?'stale':'current',label:`${stale?'Senesnė kaina':'Atnaujinta'} · ${localTime(timestamp)}`};
  }
  function priceMarkup(station){
    const price=priceValue(station),status=priceStatus(station);
    if(price==null)return `<strong class="price is-missing">Kaina nepateikta</strong><span class="price-note is-missing">${status.label}</span>`;
    return `<strong class="price${status.kind==='stale'?' is-stale':''}">${euro(price)}</strong><span class="price-note${status.kind==='stale'?' is-stale':''}">${status.label||'už litrą'}</span>`;
  }
  function comparePrices(a,b,direction=1){const left=priceValue(a),right=priceValue(b);if(left==null&&right==null)return a.brand.localeCompare(b.brand,'lt');if(left==null)return 1;if(right==null)return -1;return (left-right)*direction;}

  function distance(aLat,aLng,bLat,bLng){const r=6371,dLat=(bLat-aLat)*Math.PI/180,dLng=(bLng-aLng)*Math.PI/180;const a=Math.sin(dLat/2)**2+Math.cos(aLat*Math.PI/180)*Math.cos(bLat*Math.PI/180)*Math.sin(dLng/2)**2;return r*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));}
  function fuelStations(){
    if(!state.data)return [];
    const query=value('[data-search]').toLocaleLowerCase('lt');
    return state.data.stations.filter(s => declaresFuel(s))
      .filter(s => !value('[data-city]') || cityKey(s.city)===cityKey(value('[data-city]')))
      .filter(s => !value('[data-brand]') || s.brand===value('[data-brand]'))
      .filter(s => !query || [s.name,s.brand,s.address,s.city,s.municipality].join(' ').toLocaleLowerCase('lt').includes(query))
      .map(s => ({...s,distance_km:state.lat!=null&&hasCoordinates(s)?distance(state.lat,state.lng,Number(s.latitude),Number(s.longitude)):null}))
      .sort((a,b) => {const mode=value('[data-sort]');if(mode==='price-desc')return comparePrices(a,b,-1);if(mode==='name')return a.brand.localeCompare(b.brand,'lt');if(mode==='distance')return ((a.distance_km??Infinity)-(b.distance_km??Infinity))||comparePrices(a,b);return comparePrices(a,b);});
  }
  function options(selector,items,first){$(selector).innerHTML=`<option value="">${first}</option>`+[...items].sort((a,b)=>a.localeCompare(b,'lt')).map(item=>`<option>${escapeHtml(item)}</option>`).join('');}
  function syncMapFilters(){$('[data-map-city]').value=value('[data-city]');$('[data-map-brand]').value=value('[data-brand]');}
  function renderSource(){
    const s=state.data.source,node=$('[data-source]'),timestamp=state.checkedAt||s.generated_at,sourceTimestamp=s.source_updated_at||s.source_date;
    const checkedDate=new Date(timestamp),sourceDate=new Date(sourceTimestamp),now=Date.now();
    const checkedLate=Number.isNaN(checkedDate.getTime())||now-checkedDate.getTime()>3.5*60*60*1000;
    const sourceLate=Number.isNaN(sourceDate.getTime())||now-sourceDate.getTime()>36*60*60*1000;
    node.classList.remove('demo','warning','stale');
    if(state.data.demo){node.classList.add('demo');node.lastChild.textContent=' Demonstraciniai duomenys';showNotice('Rodoma demonstracinė duomenų kopija.','info');return;}
    if(checkedLate){node.classList.add('stale');node.lastChild.textContent=` Automatinis duomenų patikrinimas vėluoja. Rodoma ${localTime(sourceTimestamp)} kopija · paskutinį kartą patikrinta ${localTime(timestamp)}`;showNotice('Duomenų tikrinimas vėluoja. Rodoma paskutinė sėkmingai patikrinta kopija, todėl dalis kainų gali būti pasenusios.');return;}
    if(sourceLate){node.classList.add('warning');node.lastChild.textContent=` Naujesnių duomenų dar nepateikta. Rodoma ${localTime(sourceTimestamp)} kopija · patikrinta ${localTime(timestamp)}`;showNotice('Automatinis tikrinimas veikia, tačiau naujesnių kainų dar nepateikta.','info');return;}
    node.lastChild.textContent=` Kainos atnaujintos: ${localTime(sourceTimestamp)} · patikrinta ${localTime(timestamp)}`;
  }
  function renderTabs(){const fuels=state.data.summary.fuels.filter(f=>fuelLabels[f]);if(!fuels.includes(state.fuel))state.fuel=fuels[0];const buttons=fuels.map(f=>`<button type="button" class="${f===state.fuel?'active':''}" data-fuel="${f}"><span>${fuelLabels[f]}</span><small>${integer(state.data.stations.filter(s=>s.prices?.[f]!=null).length)} su kaina</small></button>`).join('');$('[data-fuels]').innerHTML=buttons;$('[data-map-fuels]').innerHTML=buttons;$('[data-summary-fuel]').textContent=fuelLabels[state.fuel];document.querySelectorAll('[data-fuel]').forEach(b=>b.onclick=()=>{state.fuel=b.dataset.fuel;state.page=1;renderAll();});}
  function renderSummary(rows){const prices=rows.map(s=>priceValue(s)).filter(price=>price!=null);$('[data-average]').textContent=euro(prices.length?prices.reduce((a,b)=>a+b,0)/prices.length:null);$('[data-minimum]').textContent=euro(prices.length?Math.min(...prices):null);$('[data-count]').textContent=integer(rows.length);}
  function renderTop(rows){const nearby=value('[data-sort]')==='distance'&&state.lat!=null,priced=rows.filter(s=>priceValue(s)!=null);$('[data-top-kicker]').textContent=nearby?'Iš degalinių su patikrinta vieta':'Pigiausi pagal pasirinktus filtrus';$('[data-top-title]').textContent=nearby?'Artimiausios degalinės':'Degalinių TOP 3';$('[data-top-fuel]').textContent=fuelLabels[state.fuel];$('[data-top]').innerHTML=priced.slice(0,3).map((s,i)=>`<article class="top-card"><span class="rank">${i+1}</span><div class="station-copy"><strong>${escapeHtml(s.brand)}</strong><small>${escapeHtml(s.address)}${s.city?', '+escapeHtml(s.city):''}${s.distance_km!=null?' · '+s.distance_km.toFixed(1)+' km':''}</small></div><span class="price">${euro(priceValue(s))}</span></article>`).join('')||'<p class="empty">Pagal pasirinktus filtrus pateiktų kainų nerasta.</p>';}
  const cityKey = city => String(city||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('lt').replace(/[^a-z0-9]/g,'');
  const lithuanianLetterCount = city => (String(city||'').match(/[ąčęėįšųūž]/gi)||[]).length;
  function canonicalCities(stations){
    const cities=new Map();
    stations.map(station=>station.city).filter(Boolean).forEach(city=>{
      const key=cityKey(city),current=cities.get(key);
      if(!current||lithuanianLetterCount(city)>lithuanianLetterCount(current))cities.set(key,city);
    });
    return new Set(cities.values());
  }
  function renderCityRanking(){
    const cities=['Vilnius','Kaunas','Klaipėda','Šiauliai','Panevėžys','Alytus','Marijampolė'];
    const cards=cities.map(city=>{
      const candidates=state.data.stations.filter(s=>cityKey(s.city)===cityKey(city)&&priceValue(s)!=null).sort(comparePrices);
      const station=candidates[0];
      return station?`<button class="city-card" type="button" data-ranking-city="${escapeHtml(city)}"><span>${escapeHtml(city)}</span><strong>${euro(priceValue(station))}<small>/l</small></strong><b>${escapeHtml(station.brand)}</b><small>${escapeHtml(station.address)}</small><i>Visos degalinės →</i></button>`:`<article class="city-card is-empty"><span>${escapeHtml(city)}</span><strong>—</strong><small>Kaina nepateikta</small></article>`;
    });
    $('[data-city-ranking]').innerHTML=cards.join('');
    $$('[data-ranking-city]').forEach(button=>button.onclick=()=>{const city=button.dataset.rankingCity;$('[data-city]').value=city;state.page=1;renderAll();document.querySelector('.filters').scrollIntoView({behavior:'smooth',block:'center'});});
  }
  function renderHistory(){
    let availableDays=state.history?.days||[];
    if(!availableDays.length){const fuels={};state.data.summary.fuels.forEach(fuel=>{const priced=state.data.stations.filter(s=>priceValue(s,fuel)!=null).sort((a,b)=>priceValue(a,fuel)-priceValue(b,fuel));if(priced.length){const prices=priced.map(s=>priceValue(s,fuel));fuels[fuel]={minimum:prices[0],average:prices.reduce((a,b)=>a+b,0)/prices.length,station_count:prices.length,winner:{id:priced[0].id,brand:priced[0].brand,address:priced[0].address,city:priced[0].city,price:prices[0]}};}});availableDays=[{date:state.data.source.source_date,fuels}];}
    const days=availableDays.filter(day=>day?.fuels?.[state.fuel]);
    $('[data-history-fuel]').textContent=fuelLabels[state.fuel];
    const periods=[['Naujausia diena',1],['7 dienos',7],['30 dienų',30]];
    $('[data-period-ranking]').innerHTML=periods.map(([label,count])=>{
      const slice=days.slice(-count);let best=null;
      slice.forEach(day=>{const entry=day.fuels[state.fuel];if(entry?.winner&&(!best||entry.minimum<best.minimum))best={...entry,date:day.date};});
      if(!best)return `<article class="period-card"><span>${label}</span><strong>Istorija kaupiama</strong><small>Duomenys atsiras po sėkmingų kasdienių importų.</small></article>`;
      return `<article class="period-card"><span>${label}</span><strong>${escapeHtml(best.winner.brand)}</strong><small>${escapeHtml(best.winner.city||best.winner.address)}</small><b>${euro(best.minimum)}<i>/l</i></b><em>${slice.length} d. istorijos</em></article>`;
    }).join('');
    const points=days.slice(-30).map(day=>({date:day.date,value:Number(day.fuels[state.fuel].minimum)})).filter(p=>Number.isFinite(p.value));
    if(points.length<2){$('[data-trend-chart]').innerHTML='<span class="trend-empty">Tendencijai reikia bent dviejų dienų duomenų.</span>';$('[data-trend-summary]').textContent=`Sukaupta ${points.length} d. istorija`;return;}
    const min=Math.min(...points.map(p=>p.value)),max=Math.max(...points.map(p=>p.value)),range=max-min||0.01;
    const coords=points.map((p,i)=>`${(i/(points.length-1))*100},${92-((p.value-min)/range)*72}`).join(' ');
    $('[data-trend-chart]').innerHTML=`<svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="Mažiausios kainos tendencija"><polyline points="${coords}" vector-effect="non-scaling-stroke"/></svg><span>${euro(points[0].value)}</span><span>${euro(points[points.length-1].value)}</span>`;
    const change=points[points.length-1].value-points[0].value;$('[data-trend-summary]').textContent=`Per laikotarpį ${change===0?'nepasikeitė':`${change>0?'pakilo':'nukrito'} ${Math.abs(change).toFixed(3).replace('.',',')} €`}`;
  }
  function storedAlert(){try{return JSON.parse(localStorage.getItem('kuras-price-alert')||'null');}catch(_){return null;}}
  function renderAlert(rows){const alert=storedAlert(),status=$('[data-alert-status]');if(!alert){status.hidden=true;return;}const prices=rows.filter(s=>alert.fuel===state.fuel).map(s=>priceValue(s)).filter(v=>v!=null);const minimum=prices.length?Math.min(...prices):null;status.hidden=false;status.className=`alert-status${minimum!=null&&minimum<=alert.price?' reached':''}`;status.textContent=minimum!=null&&minimum<=alert.price?`Tikslas pasiektas: ${fuelLabels[alert.fuel]} mažiausia kaina dabar ${euro(minimum)}.`:`Perspėjimas aktyvus: ${fuelLabels[alert.fuel]} iki ${euro(alert.price)}.`;}
  function renderTable(rows){
    const pages=Math.max(1,Math.ceil(rows.length/state.perPage));
    state.page=Math.min(state.page,pages);
    const shown=rows.slice((state.page-1)*state.perPage,state.page*state.perPage);
    const pricedCount=rows.filter(s=>priceValue(s)!=null).length;
    $('[data-results-count]').textContent=`Rasta ${integer(rows.length)} · kainą pateikė ${integer(pricedCount)}`;
    $('[data-page]').textContent=`${state.page} iš ${pages}`;
    $('[data-prev]').disabled=state.page<=1;
    $('[data-next]').disabled=state.page>=pages;
    $('[data-table]').innerHTML=shown.map(s=>{
      const id=String(s.id);
      const selected=id===state.selectedStationId;
      const mapped=hasCoordinates(s);
      const label=mapped?`Rodyti degalinę ${s.name||s.brand} žemėlapyje`:`Ieškoti degalinės ${s.name||s.brand} žemėlapyje pagal adresą`;
      const mapStatus=mapped?'':`<span class="map-status">Vieta tikslinama · atidaryti pagal adresą <span aria-hidden="true">↗</span></span>`;
      return `<tr class="station-row${selected?' is-selected':''}${mapped?'':' needs-map'}" data-station-id="${escapeHtml(id)}" tabindex="0" role="button" aria-label="${escapeHtml(label)}" aria-pressed="${selected?'true':'false'}"><td><span class="station-name">${escapeHtml(s.name||s.brand)}</span><span class="brand">${escapeHtml(s.brand)}</span></td><td><span class="address">${escapeHtml(s.address)}<br>${escapeHtml(s.city||s.municipality||'')}${s.distance_km!=null?' · '+s.distance_km.toFixed(1)+' km':''}</span>${mapStatus}</td><td>${priceMarkup(s)}</td></tr>`;
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
  function popupHtml(s){const address=String(s.address||'');const city=s.city&&!address.toLocaleLowerCase('lt').includes(String(s.city).toLocaleLowerCase('lt'))?`<span>${escapeHtml(s.city)}</span>`:'';const distance=s.distance_km!=null?`<span class="station-popup-distance">${s.distance_km.toFixed(1)} km nuo jūsų</span>`:'';const route=`https://www.google.com/maps/dir/?api=1&destination=${Number(s.latitude)},${Number(s.longitude)}`;const status=priceStatus(s),price=priceValue(s);return `<article class="station-popup"><span class="station-popup-label">${escapeHtml(fuelLabels[state.fuel])}</span><strong class="station-popup-name">${escapeHtml(s.brand)}</strong><p>${escapeHtml(address)}${city}</p><div class="station-popup-footer"><div><small>${price==null?'Kainos būsena':'Kaina už litrą'}</small><b class="${price==null?'is-missing':status.kind==='stale'?'is-stale':''}">${price==null?'Nepateikta':euro(price)}</b>${status.label?`<span class="station-popup-status">${escapeHtml(status.label)}</span>`:''}${distance}</div><a href="${route}" target="_blank" rel="noopener">Maršrutas <span aria-hidden="true">↗</span></a></div></article>`;}
  function renderMap(rows){
    initMap();
    if(!state.map)return;
    state.markers.clearLayers();
    state.userLayers.clearLayers();
    state.markerByStationId.clear();
    const mapped=rows.filter(hasCoordinates),pricedMapped=mapped.filter(s=>priceValue(s)!=null);
    const mapPrices=pricedMapped.map(s=>priceValue(s));
    const mapAverage=mapPrices.length?mapPrices.reduce((sum,price)=>sum+price,0)/mapPrices.length:null;
    const priceBand=station=>{const price=priceValue(station);if(price==null||mapAverage==null)return 'unavailable';if(price<=mapAverage-.02)return 'cheap';if(price>=mapAverage+.02)return 'expensive';return 'average';};
    const emphasized=new Set((state.lat!=null?pricedMapped.slice(0,10):[...pricedMapped].sort(comparePrices).slice(0,12)).map(s=>String(s.id)));
    mapped.forEach(s=>{
      const id=String(s.id);
      const selected=id===state.selectedStationId;
      const popup=popupHtml(s);
      const popupOptions={minWidth:240,maxWidth:290,offset:[0,-3],autoPanPaddingTopLeft:[58,20],autoPanPaddingBottomRight:[20,20]};
      let marker;
      if(emphasized.has(id)||selected){
        const missing=priceValue(s)==null,markerClass=selected?'selected':priceBand(s);
        marker=L.marker([Number(s.latitude),Number(s.longitude)],{icon:L.divIcon({className:'price-marker',html:`<span class="marker ${markerClass}">${missing?'Nepateikė':euro(priceValue(s))}</span>`,iconSize:[missing?88:76,30],iconAnchor:[missing?44:38,15]})}).bindPopup(popup,popupOptions).addTo(state.markers);
      }else{
        const colors={cheap:'#20ad58',average:'#e6ad00',expensive:'#df4848',unavailable:'#8b969e'};
        marker=L.circleMarker([Number(s.latitude),Number(s.longitude)],{radius:6,color:'#fff',weight:1.5,fillColor:colors[priceBand(s)],fillOpacity:.9}).bindPopup(popup,popupOptions).addTo(state.markers);
      }
      state.markerByStationId.set(id,marker);
    });
    if(state.lat!=null){
      if(state.accuracy)L.circle([state.lat,state.lng],{radius:Math.min(state.accuracy,2000),color:'#326f94',weight:1,fillColor:'#326f94',fillOpacity:.08,interactive:false}).addTo(state.userLayers);
      L.circleMarker([state.lat,state.lng],{radius:9,color:'#fff',weight:3,fillColor:'#326f94',fillOpacity:1}).bindTooltip('Jūsų vieta',{permanent:true,direction:'top',offset:[0,-10]}).addTo(state.userLayers);
    }
    const topMapped=[...pricedMapped].sort(state.lat!=null?(a,b)=>(a.distance_km??Infinity)-(b.distance_km??Infinity):comparePrices).slice(0,5);
    $('[data-map-top]').innerHTML=topMapped.map((station,index)=>`<li><button type="button" data-map-station-id="${escapeHtml(String(station.id))}"><span>${index+1}. ${escapeHtml(station.brand)}</span><b>${euro(priceValue(station))}</b><small>${escapeHtml(station.city||station.address)}${station.distance_km!=null?' · '+station.distance_km.toFixed(1)+' km':''}</small></button></li>`).join('')||'<li class="empty">Degalinių nerasta.</li>';
    $$('[data-map-station-id]').forEach(button=>button.onclick=()=>selectStation(button.dataset.mapStationId));
    const selected=mapped.find(s=>String(s.id)===state.selectedStationId);
    $('[data-map-note]').textContent=!mapped.length?'Tikslios pasirinktų degalinių koordinatės dar tikslinamos.':selected?`Pasirinkta: ${selected.name||selected.brand}, ${selected.address}.`:state.lat!=null?`Pirmiausia rodomos artimiausios iš ${integer(mapped.length)} degalinių su patikrinta vieta.`:`Rodoma ${integer(pricedMapped.length)} degalinių su kaina ir ${integer(mapped.length-pricedMapped.length)}, kurios kainos nepateikė. Paspauskite degalinę sąraše, kad ją rastumėte žemėlapyje.`;
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
      showNotice('Tiksli šios degalinės vieta dar tikslinama. Atidarėme žemėlapio paiešką pagal pateiktą adresą.','info');
      return;
    }
    state.selectedStationId=String(id);
    state.focusStationId=String(id);
    showMapView();
    const rows=fuelStations();
    renderTable(rows);
    requestAnimationFrame(()=>renderMap(rows));
  }
  function renderAll(){renderTabs();syncMapFilters();const rows=fuelStations();if(state.selectedStationId&&!rows.some(s=>String(s.id)===state.selectedStationId)){state.selectedStationId=null;state.focusStationId=null;}renderSummary(rows);renderTop(rows);renderCityRanking();renderHistory();renderAlert(rows);renderTable(rows);renderMap(rows);}
  function setLocationButtons({disabled=false,text='Naudoti mano vietą',ready=false,title=''}){$$('[data-locate]').forEach(button=>{button.disabled=disabled;button.textContent=text;button.title=title;button.classList.toggle('is-ready',ready);});}
  function showNotice(message,type='error'){const notice=$('[data-notice]');notice.hidden=false;notice.className=`notice${type==='info'?' info':''}`;notice.textContent=message;}
  function geolocationMessage(error){if(error?.code===1)return 'Vietos leidimas nesuteiktas. Telefono arba naršyklės nustatymuose leiskite šiam puslapiui naudoti vietą.';if(error?.code===2)return 'Įrenginiui nepavyko nustatyti vietos. Patikrinkite, ar telefone įjungta vietos nustatymo funkcija.';if(error?.code===3)return 'Vietos nustatymas užtruko per ilgai. Pabandykite dar kartą vietoje, kur geresnis GPS signalas.';return 'Vietos nustatyti nepavyko. Pabandykite dar kartą.';}
  function locate(){const coordinateCount=state.data?.stations.filter(hasCoordinates).length||0;if(!coordinateCount){showNotice('Artimiausių degalinių skaičiavimas bus įjungtas, kai prie adresų bus prijungtos patikrintos koordinatės.','info');return;}if(!navigator.geolocation){showNotice('Ši naršyklė nepalaiko vietos nustatymo. Galite toliau ieškoti pagal miestą ar adresą.');return;}setLocationButtons({disabled:true,text:'Nustatoma vieta…'});navigator.geolocation.getCurrentPosition(p=>{state.lat=p.coords.latitude;state.lng=p.coords.longitude;state.accuracy=p.coords.accuracy;state.focusLocation=true;$('[data-sort] option[value="distance"]').disabled=false;$('[data-sort]').value='distance';state.page=1;$('[data-notice]').hidden=true;renderAll();setLocationButtons({text:'Vieta nustatyta · atnaujinti',ready:true,title:'Paspauskite dar kartą vietai atnaujinti'});},error=>{setLocationButtons({text:'Bandykite dar kartą'});showNotice(geolocationMessage(error));},{enableHighAccuracy:true,timeout:12000,maximumAge:300000});}
  async function start(){try{const statusPromise=fetch(`data/status.json?t=${Date.now()}`,{cache:'no-store'}).then(response=>response.ok?response.json():null).catch(()=>null);const historyPromise=fetch(`data/history.json?t=${Date.now()}`,{cache:'no-store'}).then(response=>response.ok?response.json():{days:[]}).catch(()=>({days:[]}));if(window.__KURAS_DATA){state.data=window.__KURAS_DATA;}else{const response=await fetch('data/current.json',{cache:'no-store'});if(!response.ok)throw new Error();state.data=await response.json();}const [status,history]=await Promise.all([statusPromise,historyPromise]);state.checkedAt=status?.checked_at||null;state.history=history||{days:[]};const cities=canonicalCities(state.data.stations),brands=new Set(state.data.stations.map(s=>s.brand).filter(Boolean));options('[data-city]',cities,'Visa Lietuva');options('[data-map-city]',cities,'Visa Lietuva');options('[data-brand]',brands,'Visi tinklai');options('[data-map-brand]',brands,'Visi tinklai');$('[data-alert-fuel]').innerHTML=state.data.summary.fuels.filter(f=>fuelLabels[f]).map(f=>`<option value="${f}">${fuelLabels[f]}</option>`).join('');const existingAlert=storedAlert();if(existingAlert){$('[data-alert-fuel]').value=existingAlert.fuel;$('[data-alert-price]').value=Number(existingAlert.price).toFixed(3);}const coordinateCount=state.data.stations.filter(hasCoordinates).length;$('[data-coordinate-coverage]').textContent=coordinateCount?`${integer(coordinateCount)} iš ${integer(state.data.stations.length)} degalinių turi patikrintą vietą žemėlapyje.`:'Patikrintų degalinių koordinačių dar nėra.';renderSource();renderAll();if(!coordinateCount)setLocationButtons({disabled:true,text:'Artimiausios – ruošiama',title:'Laukiama patikrintų degalinių koordinačių'});}catch(_){showNotice('Kainų failo gauti nepavyko. Automatinis atnaujinimas išsaugojo paskutinę gerą versiją.');}}
  function publishHeight(){if(!embedded||window.parent===window)return;window.parent.postMessage({type:'kuras-pricer:height',height:Math.ceil(document.documentElement.scrollHeight)},'*');}
  if(embedded){if('ResizeObserver' in window)new ResizeObserver(publishHeight).observe(document.body);window.addEventListener('load',publishHeight);}
  $('[data-filters]').onsubmit=e=>{e.preventDefault();state.page=1;state.fitResults=state.lat==null;renderAll();};$('[data-alert-form]').onsubmit=e=>{e.preventDefault();const fuel=$('[data-alert-fuel]').value,price=Number(String($('[data-alert-price]').value).replace(',','.'));if(!fuel||!Number.isFinite(price)||price<.5||price>4){showNotice('Įveskite tikslinę kainą nuo 0,500 iki 4,000 €/l.');return;}localStorage.setItem('kuras-price-alert',JSON.stringify({fuel,price}));state.fuel=fuel;state.page=1;renderAll();};$('[data-map-city]').onchange=e=>{$('[data-city]').value=e.target.value;state.page=1;state.fitResults=true;renderAll();};$('[data-map-brand]').onchange=e=>{$('[data-brand]').value=e.target.value;state.page=1;state.fitResults=true;renderAll();};$('[data-prev]').onclick=()=>{state.page--;renderTable(fuelStations());};$('[data-next]').onclick=()=>{state.page++;renderTable(fuelStations());};$$('[data-locate]').forEach(button=>button.onclick=locate);document.querySelectorAll('[data-view-button]').forEach(b=>b.onclick=()=>{document.querySelectorAll('[data-view-button]').forEach(x=>x.classList.toggle('active',x===b));const showMap=b.dataset.viewButton==='map';$('.results').classList.toggle('show-map',showMap);if(showMap)requestAnimationFrame(()=>renderMap(fuelStations()));else syncMap(false);});start();
})();
