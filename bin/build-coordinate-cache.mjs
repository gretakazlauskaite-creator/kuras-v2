#!/usr/bin/env node

import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const OVERPASS_ENDPOINT = 'https://overpass-api.de/api/interpreter';
const OVERPASS_QUERY = '[out:json][timeout:180];area["ISO3166-1"="LT"][admin_level=2]->.searchArea;(node["amenity"="fuel"](area.searchArea);way["amenity"="fuel"](area.searchArea);relation["amenity"="fuel"](area.searchArea););out center tags;';

const args = Object.fromEntries(process.argv.slice(2).map((arg) => {
  const [key, ...value] = arg.replace(/^--/, '').split('=');
  return [key, value.join('=') || true];
}));

if (args.help) {
  console.log('Usage: node bin/build-coordinate-cache.mjs --data=static/data/current.json [--osm=file.json | --fetch-osm] [--output=resources/station-coordinates.json]');
  process.exit(0);
}

const dataPath = resolve(String(args.data || 'static/data/current.json'));
const outputPath = resolve(String(args.output || 'resources/station-coordinates.json'));
const payload = JSON.parse(await readFile(dataPath, 'utf8'));
const osm = args.osm
  ? JSON.parse(await readFile(resolve(String(args.osm)), 'utf8'))
  : await fetchFuelStations();

const candidates = (osm.elements || []).map(osmCandidate).filter(Boolean);
const possibleMatches = [];

for (const station of payload.stations || []) {
  for (const candidate of candidates) {
    const match = scoreMatch(station, candidate);
    if (match.accepted) possibleMatches.push({ station, candidate, ...match });
  }
}

possibleMatches.sort((left, right) => right.score - left.score);
const usedStations = new Set();
const usedOsm = new Set();
const coordinates = {};

for (const match of possibleMatches) {
  if (usedStations.has(match.station.id) || usedOsm.has(match.candidate.key)) continue;
  usedStations.add(match.station.id);
  usedOsm.add(match.candidate.key);
  coordinates[match.station.id] = {
    latitude: round(match.candidate.latitude),
    longitude: round(match.candidate.longitude),
    confidence: Math.min(1, round(match.score / 140, 3)),
    match_method: 'osm-fuel-address',
    osm_type: match.candidate.type,
    osm_id: match.candidate.id,
  };
}

const result = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  source: {
    name: 'OpenStreetMap fuel stations',
    url: 'https://www.openstreetmap.org/',
    license: 'ODbL',
  },
  summary: {
    lea_stations: (payload.stations || []).length,
    osm_fuel_stations: candidates.length,
    matched_stations: Object.keys(coordinates).length,
  },
  stations: Object.fromEntries(Object.entries(coordinates).sort(([left], [right]) => left.localeCompare(right))),
};

await mkdir(dirname(outputPath), { recursive: true });
await writeFile(outputPath, JSON.stringify(result, null, 2) + '\n');
console.log(`Matched ${result.summary.matched_stations} of ${result.summary.lea_stations} LEA stations to ${result.summary.osm_fuel_stations} OSM fuel stations.`);

async function fetchFuelStations() {
  const body = new URLSearchParams({ data: OVERPASS_QUERY });
  const response = await fetch(OVERPASS_ENDPOINT, {
    method: 'POST',
    headers: {
      'content-type': 'application/x-www-form-urlencoded;charset=UTF-8',
      'user-agent': 'kuras.pricer.lt/2.0 (+https://kuras.pricer.lt/)',
    },
    body,
  });
  if (!response.ok) throw new Error(`Overpass request failed with HTTP ${response.status}`);
  return response.json();
}

function osmCandidate(element) {
  const latitude = Number(element.lat ?? element.center?.lat);
  const longitude = Number(element.lon ?? element.center?.lon);
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return null;
  if (latitude < 53.8 || latitude > 56.5 || longitude < 20.6 || longitude > 26.9) return null;
  const tags = element.tags || {};
  return {
    key: `${element.type}/${element.id}`,
    type: element.type,
    id: element.id,
    latitude,
    longitude,
    brandNames: [tags.brand, tags.operator, tags.name].filter(Boolean).map(cleanBrand),
    cityNames: [tags['addr:city'], tags['addr:place'], tags['addr:suburb'], tags.place].filter(Boolean).map(cleanPlace),
    postcode: digits(tags['addr:postcode']),
    street: cleanStreet(tags['addr:street'] || ''),
    house: cleanHouse(tags['addr:housenumber'] || ''),
  };
}

function scoreMatch(station, candidate) {
  const address = parseAddress(station);
  const stationBrand = cleanBrand(station.brand || station.name || '');
  const brand = bestSimilarity(stationBrand, candidate.brandNames);
  const city = bestSimilarity(cleanPlace(station.city || ''), candidate.cityNames);
  const street = similarity(address.street, candidate.street);
  const postcodeExact = Boolean(address.postcode && candidate.postcode && address.postcode === candidate.postcode);
  const houseExact = Boolean(address.house && candidate.house && address.house === candidate.house);

  let score = 0;
  if (brand === 1) score += 55;
  else if (brand >= 0.55) score += 38;
  else if (stationBrand && candidate.brandNames.length) score -= 12;

  if (city === 1) score += 28;
  else if (city >= 0.55) score += 18;
  else if (station.city && candidate.cityNames.length) score -= 10;

  if (postcodeExact) score += 28;
  else if (address.postcode && candidate.postcode) score -= 8;

  if (street === 1) score += 38;
  else if (street >= 0.55) score += 25;
  else if (address.street && candidate.street) score -= 8;

  if (houseExact) score += 25;
  else if (address.house && candidate.house) score -= 20;

  const exactAddress = street >= 0.9 && houseExact && (city >= 0.55 || postcodeExact);
  const brandedPlace = brand >= 0.55 && (city >= 0.55 || postcodeExact || (street >= 0.9 && houseExact));
  const unbrandedAddress = street >= 0.9 && (houseExact || postcodeExact) && city >= 0.55;
  return { score, accepted: score >= 62 && (exactAddress || brandedPlace || unbrandedAddress) };
}

function parseAddress(station) {
  const parts = String(station.address || '').split(',').map((part) => part.trim()).filter(Boolean);
  const streetPart = parts.find((part) => /\b(g|gatvė|pr|prospektas|pl|plentas|al|alėja|kel|kelias|kl|tak|takas|skg|skersgatvis)\.?\s+/iu.test(part)) || '';
  const houseMatch = streetPart.match(/(?:^|\s)(\d+)\s*([a-z]?)(?:-(\d+[a-z]?))?\b/iu);
  const house = houseMatch ? `${houseMatch[1]}${houseMatch[2] || ''}${houseMatch[3] ? `-${houseMatch[3]}` : ''}` : '';
  return {
    postcode: digits(String(station.address || '').match(/\b\d{5}\b/u)?.[0] || ''),
    street: cleanStreet(streetPart.replace(house, '')),
    house: cleanHouse(house),
  };
}

function cleanBrand(value) {
  let text = normalize(value)
    .replace(/^(uab|ab|mb|ii|z ub|kooperatyvas)\s+/u, '')
    .replace(/\bdegaline\b/gu, '')
    .replace(/\blietuva\b/gu, '')
    .trim();
  const aliases = [
    [/orlen.*baltics.*retail/u, 'orlen'],
    [/circle k/u, 'circle k'],
    [/neste/u, 'neste'],
    [/viada/u, 'viada'],
    [/virsi/u, 'virsi'],
  ];
  for (const [pattern, replacement] of aliases) if (pattern.test(text)) return replacement;
  return text;
}

function cleanPlace(value) {
  return normalize(value).replace(/\b(m|k|mstl|vs|sen)\b/gu, '').trim();
}

function cleanStreet(value) {
  return normalize(value)
    .replace(/\b(gatve|g|prospektas|pr|plentas|pl|aleja|al|kelias|kel|kl|takas|tak|skersgatvis|skg)\b/gu, '')
    .trim();
}

function cleanHouse(value) {
  return normalize(value).replace(/\s+/g, '').toUpperCase();
}

function digits(value) {
  return String(value || '').replace(/\D/g, '');
}

function normalize(value) {
  return String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase('lt')
    .replace(/[^a-z0-9]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function bestSimilarity(value, candidates) {
  return Math.max(0, ...candidates.map((candidate) => similarity(value, candidate)));
}

function similarity(left, right) {
  if (!left || !right) return 0;
  if (left === right) return 1;
  if (left.length >= 4 && right.length >= 4 && (left.includes(right) || right.includes(left))) return 0.8;
  const a = new Set(left.split(' ').filter((token) => token.length > 1));
  const b = new Set(right.split(' ').filter((token) => token.length > 1));
  if (!a.size || !b.size) return 0;
  const shared = [...a].filter((token) => b.has(token)).length;
  return shared / new Set([...a, ...b]).size;
}

function round(value, precision = 6) {
  const factor = 10 ** precision;
  return Math.round(value * factor) / factor;
}
