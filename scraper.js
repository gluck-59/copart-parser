import fs from "node:fs/promises";
import fsSync from "node:fs";
import path from "node:path";
import os from "node:os";
import { spawn } from "node:child_process";
import pLimit from "p-limit";
import { chromium } from "playwright";

// Загружаем .env из корня проекта. Реальные переменные окружения имеют приоритет над файлом.
try {
  process.loadEnvFile?.();
} catch {
  // .env отсутствует — используются значения по умолчанию ниже.
}

// const BASE_URL = "https://www.copart.com";
const BASE_URL = process.env.BASE_URL || "https://www.copart.com";
const BASE_HOST = new URL(BASE_URL).host;
const BASE_DOMAIN = BASE_HOST.replace(/^www\./, "");
const OUTPUT_DIR = path.resolve("output");
const OUTPUT_FILE = process.env.OUTPUT_FILE
  ? path.resolve(process.env.OUTPUT_FILE)
  : path.join(OUTPUT_DIR, "copart_cars.json");


const TARGET_LOTS = Number(process.env.TARGET_LOTS || 5000); // 5000
const CONCURRENCY = Number(process.env.CONCURRENCY || 15);
const IMAGES_CONCURRENCY = Number(process.env.IMAGES_CONCURRENCY || 2);
const SEARCH_MAX_PAGES = Number(process.env.SEARCH_MAX_PAGES || 300);
const SEARCH_STALL_PAGES = Number(process.env.SEARCH_STALL_PAGES || 12);
const HEADLESS = process.env.HEADLESS === "true";
const SESSION_WAIT_MS = Number(process.env.SESSION_WAIT_MS || 15000);
// Прокси для браузерной сессии (поиск). Пусто — соединение напрямую. Формат: socks5://host:port
const PROXY_URL = (process.env.PROXY_URL || "").trim();
// Настоящий Chrome вместо bundled Chromium. Incapsula отличает Playwright-запущенный
// браузер по navigator.webdriver и режет API, поэтому в идеале ставим флаг false.
const BROWSER_CHANNEL = (process.env.BROWSER_CHANNEL || "chrome").trim();
// Дополнительные флаги запуска Chrome через пробел. Нужны в контейнере:
// --no-sandbox (иначе не стартует от root), --disable-dev-shm-usage (/dev/shm в контейнере мал),
// --renderer-process-limit=1 и лимит кучи режут память на слабом VPS.
const CHROME_ARGS = (process.env.CHROME_ARGS || "").split(/\s+/).filter(Boolean);
// Порт отладочного протокола для ручного запуска Chrome (connectOverCDP).
const CDP_PORT = Number(process.env.CDP_PORT || 9333);
// Экземпляр Chrome, который уже поднят сам скрипт (убивается в конце).
let managedChrome = null;
// Copart search URL with filters. The browser navigates here to capture the filtered API request.
const SEARCH_URL = process.env.SEARCH_URL ||
  "https://www.copart.com/lotSearchResults?free=false&displayStr=AUTOMOBILE,%5B0%20TO%2034800%5D,%5B2016%20TO%202027%5D&from=%2FvehicleFinder&fromSource=widget&qId=29c7ea24-cf30-4916-bf49-5f4a83ecc29e-1773432519447&searchCriteria=%7B%22query%22:%5B%22*%22%5D,%22filter%22:%7B%22VEHT%22:%5B%22vehicle_type_code:VEHTYPE_V%22%5D,%22TITL%22:%5B%22title_group_code:TITLEGROUP_C%22,%22title_group_code:TITLEGROUP_S%22%5D,%22PRID%22:%5B%22damage_type_code:DAMAGECODE_FR%22,%22damage_type_code:DAMAGECODE_HL%22,%22damage_type_code:DAMAGECODE_MC%22,%22damage_type_code:DAMAGECODE_MN%22,%22damage_type_code:DAMAGECODE_NW%22,%22damage_type_code:DAMAGECODE_RR%22,%22damage_type_code:DAMAGECODE_RO%22,%22damage_type_code:DAMAGECODE_SD%22,%22damage_type_code:DAMAGECODE_ST%22,%22damage_type_code:DAMAGECODE_TP%22,%22damage_type_code:DAMAGECODE_UN%22,%22damage_type_code:DAMAGECODE_VN%22%5D,%22ODM%22:%5B%22odometer_reading_received:%5B0%20TO%2092100%5D%22%5D,%22YEAR%22:%5B%22lot_year:%5B2010%20TO%202026%5D%22%5D%7D,%22searchName%22:%22%22,%22watchListOnly%22:false,%22freeFormSearch%22:false%7D";

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function randomBetween(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

// Путь к настоящему Chrome/Chromium на этой машине.
function resolveChromeExecutable() {
  const candidates =
    process.platform === "darwin"
      ? [
          "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
          "/Applications/Chromium.app/Contents/MacOS/Chromium",
        ]
      : [
          "/usr/bin/google-chrome",
          "/usr/bin/google-chrome-stable",
          "/usr/bin/chromium",
          "/usr/bin/chromium-browser",
        ];

  for (const candidate of candidates) {
    if (fsSync.existsSync(candidate)) return candidate;
  }
  return null;
}

// Поднимает Chrome отдельным процессом и подключается по CDP.
// Playwright при самом запуске браузера ставит navigator.webdriver=true,
// и Incapsula режет API. Ручной запуск + connectOverCDP даёт webdriver=false.
async function connectOverRealChrome(headless) {
  const executablePath = resolveChromeExecutable();
  if (!executablePath) {
    console.warn(
      "Chrome не найден в системе — переключаюсь на запуск силами Playwright (Incapsula может резать API)."
    );
    const browser = await chromium.launch({
      headless,
      ...(BROWSER_CHANNEL ? { channel: BROWSER_CHANNEL } : {}),
    });
    return { browser, page: await (await browser.newContext()).newPage(), viaCdp: false };
  }

  const userDataDir = path.join(os.tmpdir(), `copart-cdp-${process.pid}-${Date.now()}`);
  const args = [
    `--remote-debugging-port=${CDP_PORT}`,
    `--user-data-dir=${userDataDir}`,
    "--no-first-run",
    "--no-default-browser-check",
    "--disable-blink-features=AutomationControlled",
    ...CHROME_ARGS,
    "about:blank",
  ];
  if (headless) args.unshift("--headless=new");

  console.log(`Запускаю Chrome вручную (webdriver=false), порт ${CDP_PORT}`);
  managedChrome = spawn(executablePath, args, { stdio: "ignore", detached: false });

  let lastError = null;
  for (let i = 0; i < 40; i += 1) {
    await sleep(500);
    try {
      const browser = await chromium.connectOverCDP(`http://127.0.0.1:${CDP_PORT}`);
      const context = browser.contexts()[0] || (await browser.newContext());
      const page = await context.newPage();
      return { browser, page, viaCdp: true, userDataDir };
    } catch (err) {
      lastError = err;
    }
  }

  throw new Error(`Не удалось подключиться к Chrome по CDP: ${lastError?.message || "нет ответа"}`);
}

async function shutdownChrome() {
  if (!managedChrome) return;
  try {
    managedChrome.kill("SIGTERM");
  } catch {
    // процесс уже завершён
  }
  managedChrome = null;
}

function deepClone(value) {
  return JSON.parse(JSON.stringify(value));
}

function toNumber(value) {
  if (value === null || value === undefined || value === "") return null;
  if (typeof value === "number") return Number.isFinite(value) ? value : null;
  const normalized = String(value).replace(/[^\d.-]/g, "");
  const n = Number(normalized);
  return Number.isFinite(n) ? n : null;
}

function unique(values) {
  return Array.from(new Set(values.filter(Boolean)));
}

function findFirstKeyValue(root, keys) {
  const wanted = new Set(keys.map((k) => k.toLowerCase()));
  const stack = [root];

  while (stack.length) {
    const cur = stack.pop();
    if (!cur || typeof cur !== "object") continue;

    if (Array.isArray(cur)) {
      for (const item of cur) stack.push(item);
      continue;
    }

    for (const [k, v] of Object.entries(cur)) {
      if (wanted.has(k.toLowerCase()) && v !== undefined && v !== null && v !== "") {
        return v;
      }
      if (v && typeof v === "object") stack.push(v);
    }
  }

  return null;
}

// Global cache: lotNumber (string) -> full lot object from search results
const lotDataCache = new Map();

function collectLotNumbersFromAny(root) {
  const lotNumbers = new Set();
  const stack = [root];

  while (stack.length) {
    const cur = stack.pop();
    if (!cur) continue;

    if (Array.isArray(cur)) {
      for (const item of cur) stack.push(item);
      continue;
    }

    if (typeof cur !== "object") continue;

    for (const [k, v] of Object.entries(cur)) {
      const key = k.toLowerCase();

      if (
        (key.includes("lotnumber") || key === "lot" || key === "lot_number") &&
        (typeof v === "string" || typeof v === "number")
      ) {
        const lot = String(v).replace(/\D/g, "");
        if (lot.length >= 6) lotNumbers.add(lot);
      }

      if (typeof v === "string") {
        const m = v.match(/\/lot\/(\d{6,})/i);
        if (m) lotNumbers.add(m[1]);
      }

      if (v && typeof v === "object") stack.push(v);
    }
  }

  return lotNumbers;
}

// Extract full lot objects from search response and populate lotDataCache
function cacheLotObjectsFromResponse(root) {
  const LOT_FIELDS = new Set([
    "year", "make", "vin", "color", "transmission", "drive", "fuel",
    // Copart abbreviated field names
    "lcy", "mkn", "lm", "clr", "tsmn", "drv", "ft", "fv", "mv",
  ]);
  const stack = [root];

  while (stack.length) {
    const cur = stack.pop();
    if (!cur || typeof cur !== "object" || Array.isArray(cur)) {
      if (Array.isArray(cur)) for (const item of cur) stack.push(item);
      continue;
    }

    const lotNum =
      cur.lot_number ?? cur.lotNumber ?? cur.lotNum ?? cur.ln ?? null;
    if (lotNum) {
      const lot = String(lotNum).replace(/\D/g, "");
      if (lot.length >= 6) {
        // Only cache if this object has real vehicle data (at least year or make)
        const hasVehicleData =
          Object.keys(cur).some((k) => LOT_FIELDS.has(k.toLowerCase())) ||
          cur.year || cur.make || cur.vin || cur.lcy || cur.mkn;
        if (hasVehicleData && !lotDataCache.has(lot)) {
          lotDataCache.set(lot, cur);
        }
      }
    }

    for (const v of Object.values(cur)) {
      if (v && typeof v === "object") stack.push(v);
    }
  }
}

const IMAGE_CDN_RE = /cs\.copart\.com|c-static\.copart\.com|img\.copart\.com|copartmaui\.com|copart-cdn\.com|inventoryimages/i;
const IMAGE_EXT_RE = /\.(jpe?g|png|webp|gif|bmp)(\?|$)/i;
const API_ENDPOINT_RE = /inventoryv2\.copart\.io|\/v1\/lotImages\//i;

function normalizeImageUrl(url) {
  if (!url || typeof url !== "string") return null;
  if (url.startsWith("//")) return `https:${url}`;
  if (url.startsWith("http://") || url.startsWith("https://")) return url;
  return null;
}

// Extract the unique image hash (UUID) from a Copart image URL
function imageHash(url) {
  const m = url.match(/\/([0-9a-f]{20,})_(?:v?ful|v?hrs|v?thb)\./i);
  return m ? m[1] : null;
}

// Deduplicate images: one _ful URL per unique hash; fall back to any URL if hash not found
function deduplicateImagesByHash(urls) {
  const seen = new Map(); // hash → url
  const noHash = [];
  for (const url of urls) {
    const h = imageHash(url);
    if (!h) {
      noHash.push(url);
      continue;
    }
    if (seen.has(h)) {
      // Prefer _ful over _hrs
      const existing = seen.get(h);
      if (url.includes("_ful.") && !existing.includes("_ful.")) {
        seen.set(h, url);
      }
      continue;
    }
    seen.set(h, url);
  }
  return [...seen.values(), ...noHash];
}

function isActualImageUrl(url) {
  if (!url) return false;
  if (API_ENDPOINT_RE.test(url)) return false;
  return IMAGE_CDN_RE.test(url) || IMAGE_EXT_RE.test(url);
}

function extractImages(details) {
  const urls = new Set();

  const preferredRoots = [
    findFirstKeyValue(details, ["imagesList"]),
    findFirstKeyValue(details, ["imageList"]),
    findFirstKeyValue(details, ["images"]),
  ].filter(Boolean);

  // Always include top-level object so standalone imageUrl / thumbnail_image fields are found
  const stack = preferredRoots.length ? [...preferredRoots, details] : [details];

  while (stack.length) {
    const cur = stack.pop();
    if (!cur) continue;

    if (Array.isArray(cur)) {
      for (const item of cur) stack.push(item);
      continue;
    }

    if (typeof cur !== "object") continue;

    for (const [k, v] of Object.entries(cur)) {
      if (typeof v === "string" && /(url|image|path)/i.test(k)) {
        const normalized = normalizeImageUrl(v);
        if (normalized && isActualImageUrl(normalized)) urls.add(normalized);
      }

      if (typeof v === "string") {
        const normalized = normalizeImageUrl(v);
        if (normalized && isActualImageUrl(normalized)) {
          urls.add(normalized);
        }
      }

      if (v && typeof v === "object") stack.push(v);
    }
  }

  return Array.from(urls);
}

function buildLocation(details) {
  const city = findFirstKeyValue(details, ["location_city", "locationCity", "city"]);
  const state = findFirstKeyValue(details, ["location_state", "locationState", "stateName", "state"]);
  if (city && state) {
    const zip = findFirstKeyValue(details, ["zip_code", "location_zip", "locationZip", "zip"]);
    const parts = [city, state, zip].filter(Boolean).map((x) => String(x).trim());
    return parts.join(", ");
  }

  const named = findFirstKeyValue(details, [
    "sale_name",
    "saleName",
    "yard_name",
    "yardName",
    "yn",           // Copart abbreviated: yard name
    "sale_location",
    "saleLocation",
    "locationDescription",
    "location",
  ]);
  if (typeof named === "string" && named.trim()) return named.trim();

  return null;
}

function mapLotDetails(rawDetails, lotNumber) {
  const lot = String(
    findFirstKeyValue(rawDetails, ["lotNumber", "lot_number"]) || lotNumber
  );

  const estimatedRetail = findFirstKeyValue(rawDetails, [
    "lotPlugAcv",   // Copart: Actual Cash Value (primary field)
    "estimated_retail_value",
    "est_retail_value",
    "estimatedRetailValue",
    "estRetailValue",
    "rcn",
    "actv",
    "cv",
    "lv",
    "ev",
    "pv",
    "retailValue",
    "estimatedValue",
    "actualValue",
    "cleanValue",
  ]);

  return {
    lot_number: lot,
    vin: findFirstKeyValue(rawDetails, ["vin", "fv", "mv", "maskedVin", "masked_vin"]) || null,
    year: toNumber(findFirstKeyValue(rawDetails, ["year", "lcy", "modelYear", "model_year"])),
    make: findFirstKeyValue(rawDetails, ["make", "mkn", "makeName", "make_name"]) || null,
    model: findFirstKeyValue(rawDetails, ["model_group", "modelGroup", "lm", "model", "modelName", "full_model_name"]) || null,
    trim: findFirstKeyValue(rawDetails, ["trim", "tmtp", "trimName", "trim_name"]) || null,
    body_style: findFirstKeyValue(rawDetails, ["bstl", "vty", "bodyStyle", "body_style", "vehicleStyle", "styleDesc"]) || null,
    engine: findFirstKeyValue(rawDetails, ["egn", "engine", "eng", "engineDesc", "engine_description", "engineDescription", "engineSize", "engine_size"]) || null,
    full_model_name:
      findFirstKeyValue(rawDetails, [
        "model_detail",
        "modelDetail",
        "fullModelName",
        "full_model_name",
        "ldu",          // Copart abbreviated: lot description / full title
        "titleDescription",
        "title_description",
      ]) || null,
    build_sheet: findFirstKeyValue(rawDetails, ["buildSheet", "build_sheet"]) || null,
    item_url: `${BASE_URL}/lot/${lot}`,
    color: findFirstKeyValue(rawDetails, ["color", "clr", "vehicleColor", "vehicle_color"]) || null,
    transmission: findFirstKeyValue(rawDetails, ["transmission", "tsmn", "transmissionType", "transmission_type"]) || null,
    drive: findFirstKeyValue(rawDetails, ["drive", "drv", "driveLine", "drivetrain", "drive_line"]) || null,
    fuel: findFirstKeyValue(rawDetails, ["fuel_type", "ft", "fuelType", "fuel"]) || null,
    buy_it_now_price: toNumber(findFirstKeyValue(rawDetails, ["buy_it_now_price", "bnp", "buyItNow", "buyItNowPrice"])),
    estimated_retail_value: toNumber(estimatedRetail),
    estimated_retail_value_formatted: toNumber(estimatedRetail) != null
      ? `$${Number(toNumber(estimatedRetail)).toLocaleString("en-US")}`
      : null,
    odometer: toNumber(findFirstKeyValue(rawDetails, ["odometer", "orr", "ord", "odometerReading", "odometer_reading"])),
    damage:
      findFirstKeyValue(rawDetails, ["damage_description", "dd", "damageDescription", "damage", "primaryDamage", "primary_damage"]) ||
      findFirstKeyValue(rawDetails, ["secondary_damage", "secondaryDamage"]) ||
      null,
    location: buildLocation(rawDetails),
    images: extractImages(rawDetails),
  };
}

function normalizeCarRecord(record) {
  return {
    lot_number: record?.lot_number ?? null,
    vin: record?.vin ?? null,
    year: record?.year ?? null,
    make: record?.make ?? null,
    model: record?.model ?? null,
    trim: record?.trim ?? null,
    body_style: record?.body_style ?? null,
    engine: record?.engine ?? null,
    full_model_name: record?.full_model_name ?? null,
    build_sheet: record?.build_sheet ?? null,
    item_url: record?.item_url ?? null,
    color: record?.color ?? null,
    transmission: record?.transmission ?? null,
    drive: record?.drive ?? null,
    fuel: record?.fuel ?? null,
    buy_it_now_price: record?.buy_it_now_price ?? null,
    estimated_retail_value: record?.estimated_retail_value ?? null,
    estimated_retail_value_formatted: record?.estimated_retail_value_formatted ?? null,
    odometer: record?.odometer ?? null,
    damage: record?.damage ?? null,
    location: record?.location ?? null,
    images: Array.isArray(record?.images) ? record.images : [],
  };
}

function isBaseHost(url) {
  try {
    const { host } = new URL(String(url));
    return host === BASE_HOST || host.endsWith(`.${BASE_DOMAIN}`);
  } catch {
    return false;
  }
}

function isCopartPublicApi(url) {
  if (!isBaseHost(url)) return false;
  try {
    return new URL(String(url)).pathname.startsWith("/public/");
  } catch {
    return false;
  }
}

async function initCopartSession() {
  console.log("Opening Copart session...");

  const { browser, page, viaCdp } = await connectOverRealChrome(HEADLESS);
  console.log(`Браузер подключён${viaCdp ? " через CDP (webdriver=false)" : " силами Playwright"}`);

  let seedLots = [];
  // Все номера лотов, увиденные за сессию, и порядок их появления
  const observedLots = new Set();
  const lotNumberOrder = [];
  let capturedLotDetailsUrl = null;
  let capturedLotImagesUrl = null;
  let capturedLotImagesHeaders = null;

  // Запоминаем endpoint картинок лотов — он используется как шаблон
  page.on("request", (request) => {
    if (request.url().includes("/lotdetails/")) {
      capturedLotDetailsUrl = request.url();
    }
    const rUrl = request.url().toLowerCase();
    if (
      isCopartPublicApi(rUrl) &&
      rUrl.includes("image") &&
      !rUrl.includes("/search") &&
      !rUrl.includes("search?") &&
      ["xhr", "fetch"].includes(request.resourceType())
    ) {
      if (!capturedLotImagesUrl) capturedLotImagesUrl = request.url();
    }
  });

  // Каждый JSON-ответ браузера разбирается на лоты. Браузер уже прошёл Incapsula,
  // поэтому его ответы — единственный надёжный источник данных.
  page.on("response", async (response) => {
    if (response.url().includes("/lotdetails/")) {
      capturedLotDetailsUrl = response.url();
    }

    if (!["xhr", "fetch"].includes(response.request().resourceType())) return;

    let data = null;
    try {
      data = await response.json();
    } catch {
      return;
    }
    if (!data || typeof data !== "object") return;

    // Картинки лотов: запоминаем endpoint, если в ответе есть реальные URL
    const json = JSON.stringify(data);
    if (/ids-c-prod-lpp|_ful\.jpg|_thb\.jpg|_hrs\.jpg/.test(json) && !capturedLotImagesUrl) {
      capturedLotImagesUrl = response.url();
      console.log(`[session] Captured lot images URL from response body: ${capturedLotImagesUrl}`);
    }

    const found = Array.from(collectLotNumbersFromAny(data));
    if (found.length === 0) return;

    cacheLotObjectsFromResponse(data);
    for (const lot of found) {
      if (!observedLots.has(lot)) {
        observedLots.add(lot);
        lotNumberOrder.push(lot);
      }
    }

    if (found.length > seedLots.length) {
      seedLots = found;
    }
  });

  // Custom SEARCH_URL must be the first navigation: loading BASE_URL first makes
  // the SPA render an empty page (889 bytes) and the search request never fires
  if (!SEARCH_URL) {
    await page.goto(BASE_URL, { waitUntil: "domcontentloaded", timeout: 120000 });
    await sleep(5000);
  }

  const startUrl = SEARCH_URL || `${BASE_URL}/vehicleFinder`;
  if (SEARCH_URL) {
    console.log(`Using custom search URL: ${SEARCH_URL.slice(0, 80)}...`);
  }
  await page.goto(startUrl, { waitUntil: "domcontentloaded", timeout: 120000 });
  // lotSearchResults page auto-fires API requests — wait longer to ensure capture
  await sleep(SEARCH_URL ? 8000 : 5000);

  const safePageEvaluate = async (fn, fallback = null) => {
    for (let i = 0; i < 3; i += 1) {
      try {
        return await page.evaluate(fn);
      } catch (err) {
        const msg = String(err?.message || "");
        const transient =
          msg.includes("Execution context was destroyed") ||
          msg.includes("Cannot find context with specified id");
        if (!transient || i === 2) return fallback;
        await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => {});
        await sleep(1000);
      }
    }
    return fallback;
  };

  // Trigger at least one real search request in browser traffic.
  await safePageEvaluate(() => {
    const byText = (selector, text) =>
      Array.from(document.querySelectorAll(selector)).find((el) =>
        (el.textContent || "").toLowerCase().includes(text)
      );

    const searchBtn =
      byText("button", "search") ||
      byText("[role='button']", "search") ||
      document.querySelector("button[type='submit']") ||
      document.querySelector("[data-uname*='search']");

    if (searchBtn) searchBtn.click();
    return true;
  });

  await sleep(2000);
  await page.keyboard.press("Enter").catch(() => {});
  await sleep(SESSION_WAIT_MS);

  if (observedLots.size === 0 && !HEADLESS) {
    console.log(
      "Браузер пока не показал лотов. Если открылось окно с проверкой — решите её вручную, поиск должен запуститься сам."
    );
    for (let i = 0; i < 60 && observedLots.size === 0; i += 1) await sleep(1000);
  }

  let capturedLotPageImages = [];
  const lotPageApiResponses = [];
  if (seedLots.length > 0) {
    // Capture all XHR/fetch responses from the lot page to identify the images endpoint
    const lotPageResponseHandler = async (response) => {
      if (!["xhr", "fetch"].includes(response.request().resourceType())) return;
      const url = response.url();
      if (!isBaseHost(url)) return;
      try {
        const data = await response.json().catch(() => null);
        if (data && typeof data === "object") {
          const topKeys = Object.keys(data).slice(0, 10);
          const nestedKeys = data.data && typeof data.data === "object" ? Object.keys(data.data).slice(0, 10) : [];
          const allKeys = [...new Set([...topKeys, ...nestedKeys])];
          lotPageApiResponses.push({ url, status: response.status(), topKeys, allKeys });
        }
      } catch { /* ignore */ }
    };
    page.on("response", lotPageResponseHandler);

    await page.goto(`${BASE_URL}/lot/${seedLots[0]}`, {
      waitUntil: "domcontentloaded",
      timeout: 120000,
    });
    await sleep(4000);
    // Scroll to trigger lazy-loaded content (image gallery)
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2)).catch(() => {});
    await sleep(2000);
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight)).catch(() => {});
    await sleep(2000);

    // Try to extract image URLs directly from the page JS state
    capturedLotPageImages = await safePageEvaluate(() => {
      const urls = new Set();
      // Try Vue/Nuxt store
      try {
        const app = document.querySelector("#app")?.__vue_app__ || document.querySelector("#__nuxt")?.__vue_app__;
        const store = app?.config?.globalProperties?.$store;
        if (store) {
          const state = store.state;
          const json = JSON.stringify(state);
          const matches = json.match(/https?:\/\/[^"]*(?:ids-c-prod-lpp|lpp|copart)[^"]*\.(?:jpg|png|webp)/gi) || [];
          matches.forEach((u) => urls.add(u));
        }
      } catch {}
      // Try window.__NUXT__
      try {
        if (window.__NUXT__) {
          const json = JSON.stringify(window.__NUXT__);
          const matches = json.match(/https?:\/\/[^"]*(?:ids-c-prod-lpp|lpp|copart)[^"]*\.(?:jpg|png|webp)/gi) || [];
          matches.forEach((u) => urls.add(u));
        }
      } catch {}
      // Try all img tags and background images
      try {
        document.querySelectorAll("img[src]").forEach((el) => {
          if (/copart/i.test(el.src)) urls.add(el.src);
        });
        document.querySelectorAll("[style]").forEach((el) => {
          const m = el.getAttribute("style")?.match(/url\(['"]?([^'"()]+copart[^'"()]+)['"]?\)/i);
          if (m) urls.add(m[1]);
        });
      } catch {}
      return Array.from(urls);
    }, []) || [];

    page.removeListener("response", lotPageResponseHandler);

    if (capturedLotPageImages.length > 0) {
      console.log(`[session] Extracted ${capturedLotPageImages.length} image URLs from lot page JS state`);
    }

    // Log all lot page API endpoints to help identify the images API
    if (lotPageApiResponses.length > 0) {
      console.log(`[session] Lot page API calls (${lotPageApiResponses.length}):`);
      for (const r of lotPageApiResponses) {
        console.log(`  ${r.status} ${r.url}  keys: ${r.topKeys.join(", ")}`);
      }
    }

    // Auto-detect images endpoint from lot page responses
    if (!capturedLotImagesUrl) {
      for (const r of lotPageApiResponses) {
        const allKeys = r.allKeys || r.topKeys;
        if (allKeys.some((k) => /image|photo/i.test(k)) || r.url.toLowerCase().includes("image")) {
          capturedLotImagesUrl = r.url;
          console.log(`[session] Auto-detected images endpoint: ${capturedLotImagesUrl}`);
          break;
        }
      }
    }
  }

  // Лоты, которые SPA уже показал в разметке — запасной источник, если нужный
  // XHR так и не придёт
  const pageLotMatches =
    (await safePageEvaluate(() => {
    const html = document.documentElement?.outerHTML || "";
    const matches = html.match(/\/lot\/(\d{6,})/gi) || [];
    const lots = new Set(matches.map((m) => (m.match(/(\d{6,})/) || [])[1]).filter(Boolean));
    return Array.from(lots);
  }, [])) || [];
  for (const lot of pageLotMatches) {
    if (!observedLots.has(lot)) {
      observedLots.add(lot);
      lotNumberOrder.push(lot);
    }
  }

  console.log(`Ответов браузера дали лотов: ${observedLots.size}`);

  if (seedLots.length === 0 && pageLotMatches.length > 0) {
    seedLots = pageLotMatches;
    console.log(`Collected lot numbers: ${seedLots.length} (seed from page HTML)`);
  }

  if (capturedLotDetailsUrl) {
    console.log(`Captured lot details endpoint template: ${capturedLotDetailsUrl}`);
  }
  if (capturedLotImagesUrl) {
    console.log(`Captured lot images endpoint template: ${capturedLotImagesUrl}`);
  }

  return {
    // Браузер остаётся живым до конца сбора: данные идут через его же сессию
    browser,
    page,
    viaCdp,
    safePageEvaluate,
    capturedLotDetailsUrl,
    capturedLotImagesUrl,
    capturedLotPageImages,
    capturedLotImagesHeaders,
    seedLots,
    observedLots,
    lotNumberOrder,
  };
}

async function collectLotNumbers(session, targetLots) {
  const page = session?.page;
  const lots = new Set(session?.observedLots || []);
  for (const lot of session?.lotNumberOrder || []) lots.add(lot);

  console.log(`Лотов из ответов браузера: ${lots.size}`);

  if (!page) {
    return Array.from(lots).slice(0, targetLots);
  }

  // Пагинация через интерфейс: SPA делает запрос сам, с уже валидной сессией
  const safeEvaluate = session.safePageEvaluate || ((fn, fb) => page.evaluate(fn).catch(() => fb));
  const readLotsInDom = async () => {
    const found =
      (await safeEvaluate(() => {
        const out = new Set();
        for (const a of document.querySelectorAll("a[href*='/lot/']")) {
          const m = (a.getAttribute("href") || "").match(/\/lot\/(\d{6,})/);
          if (m) out.add(m[1]);
        }
        return Array.from(out);
      }, [])) || [];
    const added = [];
    for (const lot of found) {
      if (!lots.has(lot)) {
        lots.add(lot);
        added.push(lot);
      }
    }
    return added;
  };

  const clickNextPage = async () => {
    return safeEvaluate(() => {
      const next = () => {
        const nodes = Array.from(
          document.querySelectorAll(
            "a, button, li, [role='button'], [class*='pag'], [class*='next']"
          )
        );
        for (const el of nodes) {
          const text = (el.textContent || "").trim();
          const aria = el.getAttribute("aria-label") || "";
          const cls = el.className || "";
          const looksNext =
            /^\d+$/.test(text) || /next|siguiente|›|»|>/i.test(`${aria} ${cls}`);
          if (!looksNext) continue;
          if (/disabled|active|current|selected/i.test(cls) && !/next/i.test(cls)) continue;
          el.click();
          return (text || aria || "next").slice(0, 20);
        }
        return null;
      };
      return next();
    }, null);
  };

  let stalled = 0;
  for (let pageIndex = 0; pageIndex < SEARCH_MAX_PAGES; pageIndex += 1) {
    if (lots.size >= targetLots) break;
    if (stalled >= SEARCH_STALL_PAGES) break;

    const before = lots.size;
    const added = await readLotsInDom();
    if (added.length > 0) stalled = 0;
    else stalled += 1;

    if (lots.size > before) {
      console.log(`Страница ${pageIndex}: лотов ${lots.size} (+${added.length})`);
    }

    if (lots.size >= targetLots) break;

    const clicked = await clickNextPage();
    if (!clicked) {
      console.log(`Кнопка пагинации не найдена — останавливаюсь на ${lots.size} лотах`);
      break;
    }

    await sleep(randomBetween(1200, 2600));
    await safeEvaluate(() => window.scrollTo(0, 0), null);
    await sleep(600);
  }

  return Array.from(lots).slice(0, targetLots);
}

function buildLotDetailsCandidates(lotNumber, capturedTemplateUrl) {
  const lot = String(lotNumber);
  const urls = [];

  if (capturedTemplateUrl && !capturedTemplateUrl.includes("lot-images")) {
    const fromTemplate = capturedTemplateUrl
      .replace(/\{LOT_NUMBER\}/gi, lot)
      .replace(/(\d{6,})/, lot);
    urls.push(fromTemplate);
  }

  urls.push(`${BASE_URL}/public/data/lotdetails/solr/lotNumber/${lot}`);
  urls.push(`${BASE_URL}/public/data/lotdetails/solr/lotNumbers/${lot}`);
  urls.push(`${BASE_URL}/public/data/lotdetails/lotNumber/${lot}`);
  urls.push(`${BASE_URL}/public/data/lotdetails/solr/${lot}`);
  urls.push(`${BASE_URL}/public/data/lotdetails/${lot}`);

  return unique(urls);
}

function extractImageUrlsFromList(list) {
  if (!Array.isArray(list)) return [];
  return list
    .flatMap((item) => {
      if (typeof item === "string") return [normalizeImageUrl(item)];
      if (!item || typeof item !== "object") return [];
      // Prefer fullUrl (Copart API field), then other known fields
      const specific = [
        item?.fullUrl, item?.fullImageUrl, item?.url, item?.imageUrl,
        item?.highResUrl, item?.highRes, item?.src, item?.imageLink,
        item?.imageSrc, item?.image, item?.photo, item?.link,
        item?.filePath, item?.imageFile,
      ].map(normalizeImageUrl).filter(Boolean);
      // If we found specific fields, use only those (avoids pulling thumbnails from generic scan)
      if (specific.length > 0) return specific;
      const any = Object.values(item)
        .filter((v) => typeof v === "string")
        .map(normalizeImageUrl)
        .filter((u) => u && isActualImageUrl(u));
      return any;
    })
    .filter((u) => u && isActualImageUrl(u));
}

function parseLotImagesResponse(data) {
  if (!data) return null;
  // Unwrap common envelope layers
  const candidates = [data, data?.data, data?.data?.data, data?.result, data?.response].filter(Boolean);
  for (const payload of candidates) {
    for (const key of ["imagesList", "images", "imageList", "lotImages", "photos", "imageUrls", "imgList", "pictureList"]) {
      const val = payload?.[key];
      if (!val) continue;
      // Handle { content: [...] } wrapper (Copart lotImages API returns imagesList.content)
      const list = Array.isArray(val) ? val : Array.isArray(val?.content) ? val.content : null;
      if (list && list.length > 0) {
        const urls = extractImageUrlsFromList(list);
        if (urls.length > 0) return urls;
      }
    }
    if (Array.isArray(payload) && payload.length > 0) {
      const urls = extractImageUrlsFromList(payload);
      if (urls.length > 0) return urls;
    }
  }
  // Last resort: scan all string values in the response for image URLs
  const allUrls = [];
  const scan = (obj) => {
    if (!obj || typeof obj !== "object") return;
    for (const v of Object.values(obj)) {
      if (typeof v === "string") {
        const u = normalizeImageUrl(v);
        if (u && isActualImageUrl(u)) allUrls.push(u);
      } else if (typeof v === "object") {
        scan(v);
      }
    }
  };
  scan(data);
  return allUrls.length > 0 ? unique(allUrls) : null;
}

// Запрос идёт изнутри страницы браузера: он несёт свои cookies и заголовки,
  // поэтому Incapsula отвечает данными, а не челленджем.
async function fetchJsonInPage(page, url, method = "GET", body = null) {
  if (!page) return null;
  try {
    return await page.evaluate(
      async ([u, m, b]) => {
        const init = { method: m, credentials: "include", headers: { Accept: "application/json, text/plain, */*" } };
        if (b) {
          init.headers["Content-Type"] = "application/json";
          init.body = JSON.stringify(b);
        }
        const resp = await fetch(u, init);
        const text = await resp.text();
        if (!resp.ok) return { ok: false, status: resp.status, len: text.length, incapsula: /_Incapsula_Resource/.test(text) };
        try {
          return { ok: true, status: resp.status, data: JSON.parse(text) };
        } catch {
          return { ok: false, status: resp.status, len: text.length, notJson: true };
        }
      },
      [url, method, body]
    );
  } catch {
    return null;
  }
}

function buildLotImagesCandidates(lot, capturedLotImagesUrl) {
  const patchCapturedUrl = (tmpl) => {
    if (!tmpl) return null;
    let u = tmpl
      .replace(/\/lots\/\d+\//, `/lots/${lot}/`)
      .replace(/\/\d{6,}\/images/, `/${lot}/images`)
      .replace(/\/\d{6,}$/, `/${lot}`);
    if (u === tmpl || u.endsWith("/")) u = u.replace(/\/?$/, `/${lot}`);
    return u;
  };

  return unique([
    patchCapturedUrl(capturedLotImagesUrl),
    `${BASE_URL}/public/data/lotdetails/solr/lot-images/${lot}/ESP`,
    `${BASE_URL}/public/data/lotdetails/solr/lot-images/${lot}`,
    `${BASE_URL}/public/data/lotdetails/solr/lotNumber/${lot}/images`,
    `${BASE_URL}/public/lots/${lot}/images`,
    `${BASE_URL}/public/data/lotdetails/imagepaths/${lot}`,
    `${BASE_URL}/public/data/lotimages/${lot}`,
  ].filter(Boolean));
}

async function fetchLotImages(page, lot, capturedLotImagesUrl) {
  const candidates = buildLotImagesCandidates(lot, capturedLotImagesUrl);

  for (const url of candidates) {
    const resp = await fetchJsonInPage(page, url);
    if (!resp) continue;
    if (resp.ok) {
      const urls = parseLotImagesResponse(resp.data);
      if (urls && urls.length > 0) return urls;
    } else {
      console.log(`[images] ${url.slice(21)} → ${resp.status}${resp.incapsula ? " (Incapsula)" : ""}`);
    }
  }
  return null;
}

async function fetchLotDetails(page, lotNumber, capturedLotDetailsUrl, capturedLotImagesUrl, imagesLimit) {
  const lot = String(lotNumber);
  // Primary source: full lot object cached from search results
  const cachedLotData = lotDataCache.get(lot) || null;

  // Детали лота тоже запрашиваем из страницы браузера — из Node-контекста Incapsula режет
  const fetchInspectionData = async () => {
    const candidates = buildLotDetailsCandidates(lot, capturedLotDetailsUrl);
    for (const url of candidates) {
      const resp = await fetchJsonInPage(page, url);
      if (!resp || !resp.ok) continue;
      const data = resp.data;
      if (data?.returnCode !== undefined && data.returnCode !== 1 && data.returnCode !== 0) continue;
      const payload = data?.data || data;
      const details = payload?.lotDetails || payload?.lot || payload;
      if (details && typeof details === "object" && Object.keys(details).length > 0) return details;
    }
    return null;
  };

  const [inspectionData, lotImagesList] = await Promise.all([
    fetchInspectionData(),
    imagesLimit(async () => {
      await sleep(randomBetween(300, 600));
      return fetchLotImages(page, lot, capturedLotImagesUrl);
    }),
  ]);

  if (!cachedLotData && !inspectionData) {
    throw new Error(`No data available for lot ${lot}`);
  }

  // Merge: search result data (vehicle info) + inspection data (damage, images)
  const merged = { ...(inspectionData || {}), ...(cachedLotData || {}) };

  const record = normalizeCarRecord(mapLotDetails(merged, lot));

  // Merge additional images from the lot images API (full gallery)
  if (lotImagesList && lotImagesList.length > 0) {
    const extraUrls = lotImagesList
      .flatMap((item) => {
        if (typeof item === "string") return [normalizeImageUrl(item)];
        return [
          normalizeImageUrl(item?.url || item?.imageUrl || item?.src),
          normalizeImageUrl(item?.fullImageUrl),
          normalizeImageUrl(item?.highRes),
        ];
      })
      .filter((u) => u && isActualImageUrl(u));

    const combined = unique([...record.images, ...extraUrls]);
    record.images = combined;
  }

  // Upgrade thumbnails to full-size: _thb → _ful
  record.images = record.images.map((url) =>
    url.includes("_thb.") ? url.replace(/_thb\./, "_ful.") : url
  );

  // Deduplicate by image hash — keep one _ful URL per unique image, drop _hrs duplicates
  record.images = deduplicateImagesByHash(record.images);

  return record;
}

async function main() {
  const session = await initCopartSession();
  const page = session.page;

  try {
    console.log(
      `Браузер: ${session.viaCdp ? "Chrome вручную (webdriver=false)" : "Playwright"}, отпечаток Incapsula: webdriver=${await page.evaluate(() => navigator.webdriver)}`
    );

    if ((!session.observedLots || session.observedLots.size === 0) && (!session.seedLots || session.seedLots.length === 0)) {
      console.warn("Браузер не показал ни одного лота — Incapsula, вероятно, не пустил страницу.");
    }

    const lots = await collectLotNumbers(session, TARGET_LOTS);

    if (lots.length === 0) {
      throw new Error(
        "Could not collect lot numbers. Incapsula не отдал ни одного лота — попробуй ещё раз или запусти без HEADLESS."
      );
    }

    if (lots.length < TARGET_LOTS) {
      console.warn(`Warning: collected only ${lots.length} lots (target=${TARGET_LOTS}).`);
    }

    const limit = pLimit(CONCURRENCY);
    const imagesLimit = pLimit(IMAGES_CONCURRENCY);
    let completed = 0;

    const tasks = lots.map((lotNumber) =>
      limit(async () => {
        await sleep(randomBetween(200, 500));
        try {
          console.log(`Fetching details: ${lotNumber}`);
          const details = await fetchLotDetails(
            page,
            lotNumber,
            session.capturedLotDetailsUrl,
            session.capturedLotImagesUrl,
            imagesLimit
          );
          completed += 1;
          console.log(`Progress: ${completed}/${lots.length}`);
          return details;
        } catch (err) {
          console.warn(`Failed lot ${lotNumber}: ${err.message}`);
          return null;
        }
      })
    );

    const results = (await Promise.all(tasks)).filter(Boolean);

    await fs.mkdir(OUTPUT_DIR, { recursive: true });
    await fs.writeFile(OUTPUT_FILE, JSON.stringify(results, null, 2), "utf-8");

    console.log(`Saved: ${results.length} cars`);
    console.log(`File: ${OUTPUT_FILE}`);
  } finally {
    await page.close().catch(() => {});
    await session.browser.close().catch(() => {});
    await shutdownChrome();
  }
}

main().catch(async (err) => {
  console.error("Fatal error:", err.message);
  await shutdownChrome();
  process.exit(1);
});
