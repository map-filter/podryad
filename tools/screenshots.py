import json
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8311"
ROOT = Path(__file__).resolve().parent.parent
SHOTS = ROOT / "docs" / "screenshots"
OUT = ROOT / "tools" / "out" / "layout"

CONTRACTOR_PAGES = [
    ("dashboard", "/"),
    ("workers", "/workers"),
    ("worker", "/workers/{worker}"),
    ("requests", "/requests"),
    ("request-draft", "/requests/{draft}"),
    ("request-submitted", "/requests/{submitted}"),
    ("objects", "/objects"),
    ("types", "/document-types"),
    ("import", "/import"),
    ("journal", "/journal"),
    ("settings", "/settings"),
    ("help", "/help"),
]

CUSTOMER_PAGES = [
    ("customer-requests", "/customer"),
    ("customer-request", "/customer/requests/{submitted}"),
]

OVERFLOW_JS = """
() => {
  const doc = document.documentElement;
  const vw = doc.clientWidth;
  const bad = [];
  for (const el of document.querySelectorAll('body *')) {
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    const st = getComputedStyle(el);
    if (st.visibility === 'hidden' || st.display === 'none') continue;
    let p = el.parentElement, clipped = false;
    while (p && p !== document.body) {
      const ps = getComputedStyle(p);
      if (['auto', 'scroll', 'hidden', 'clip'].includes(ps.overflowX)) { clipped = true; break; }
      p = p.parentElement;
    }
    if (!clipped && r.right > vw + 1) bad.push((el.className || el.tagName) + ' ' + Math.round(r.right));
  }
  const texts = [...document.querySelectorAll('button, a, h1, h2, h3, label, .badge, input, select')]
    .filter(e => { const r = e.getBoundingClientRect(); return r.width && r.height && getComputedStyle(e).visibility !== 'hidden'; });
  const overlaps = [];
  for (let i = 0; i < texts.length; i++) {
    for (let j = i + 1; j < texts.length; j++) {
      const a = texts[i], b = texts[j];
      if (a.contains(b) || b.contains(a)) continue;
      const r1 = a.getBoundingClientRect(), r2 = b.getBoundingClientRect();
      const w = Math.min(r1.right, r2.right) - Math.max(r1.left, r2.left);
      const h = Math.min(r1.bottom, r2.bottom) - Math.max(r1.top, r2.top);
      if (w > 4 && h > 4) overlaps.push(`${a.tagName}:${(a.textContent||'').trim().slice(0,25)} / ${b.tagName}:${(b.textContent||'').trim().slice(0,25)}`);
    }
  }
  return { scroll: doc.scrollWidth > vw + 1, scrollWidth: doc.scrollWidth, vw, bad: bad.slice(0, 8), overlaps: overlaps.slice(0, 8) };
}
"""


def login(page, email="head@ertis.test"):
    page.goto(BASE + "/login")
    page.fill("#email", email)
    page.fill("#password", "portal2026")
    page.click("button[type=submit]")
    page.wait_for_url(lambda url: "/login" not in url, timeout=15000)
    page.wait_for_load_state("networkidle")


def ids(page):
    token = page.evaluate("() => localStorage.getItem('podryad.token')")
    res = page.request.get(BASE + "/api/requests", headers={"Authorization": "Bearer " + token, "Accept": "application/json"})
    reqs = res.json()["data"]
    workers = page.request.get(BASE + "/api/workers?search=Ковалёв", headers={"Authorization": "Bearer " + token, "Accept": "application/json"}).json()["data"]
    return {
        "draft": next(r["id"] for r in reqs if r["status"] == "draft"),
        "submitted": next((r["id"] for r in reqs if r["status"] == "submitted"), reqs[0]["id"]),
        "approved": next((r["id"] for r in reqs if r["status"] == "approved"), reqs[0]["id"]),
        "worker": workers[0]["id"],
    }


def settle(page):
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(600)


def shots(browser):
    SHOTS.mkdir(parents=True, exist_ok=True)
    ctx = browser.new_context(viewport={"width": 1440, "height": 900}, locale="ru-RU", timezone_id="Asia/Almaty")
    page = ctx.new_page()
    page.goto(BASE + "/login")
    settle(page)
    page.screenshot(path=str(SHOTS / "01-login.png"))
    login(page)
    m = ids(page)
    plan = [
        ("02-dashboard", "/", None),
        ("03-workers", "/workers", None),
        ("04-worker-card", f"/workers/{m['worker']}", None),
        ("05-requests", "/requests", None),
        ("06-request-draft", f"/requests/{m['draft']}", None),
        ("07-request-approved", f"/requests/{m['approved']}", None),
        ("08-objects-profiles", "/objects", None),
        ("09-journal", "/journal", None),
    ]
    for name, path, _ in plan:
        page.goto(BASE + path)
        settle(page)
        page.screenshot(path=str(SHOTS / f"{name}.png"))
    page.goto(BASE + "/journal")
    settle(page)
    first = page.locator(".log-head").first
    if first.count():
        first.click()
        page.wait_for_timeout(300)
        page.screenshot(path=str(SHOTS / "09-journal.png"))
    ctx.close()

    ctx = browser.new_context(viewport={"width": 1440, "height": 900}, locale="ru-RU", timezone_id="Asia/Almaty")
    page = ctx.new_page()
    login(page, "ot.pnhz@zakazchik.test")
    page.goto(BASE + "/customer")
    settle(page)
    link = page.locator("table a.cell-title").first
    link.click()
    settle(page)
    page.screenshot(path=str(SHOTS / "10-customer-review.png"), full_page=True)
    ctx.close()

    ctx = browser.new_context(viewport={"width": 390, "height": 844}, locale="ru-RU", timezone_id="Asia/Almaty", device_scale_factor=2, is_mobile=True, has_touch=True)
    page = ctx.new_page()
    login(page)
    page.goto(BASE + "/")
    settle(page)
    page.screenshot(path=str(SHOTS / "11-mobile-dashboard.png"))
    page.goto(BASE + f"/requests/{m['draft']}")
    settle(page)
    page.screenshot(path=str(SHOTS / "12-mobile-request.png"))
    ctx.close()


def layout(browser):
    OUT.mkdir(parents=True, exist_ok=True)
    report = {}
    for width in (1440, 1024, 768, 390):
        for email, pages in (("head@ertis.test", CONTRACTOR_PAGES), ("ot.pnhz@zakazchik.test", CUSTOMER_PAGES)):
            ctx = browser.new_context(viewport={"width": width, "height": 900}, locale="ru-RU", timezone_id="Asia/Almaty")
            page = ctx.new_page()
            login(page, "head@ertis.test")
            m = ids(page)
            if email != "head@ertis.test":
                page.evaluate("() => localStorage.removeItem('podryad.token')")
                login(page, email)
            for name, path in pages:
                page.goto(BASE + path.format(**m))
                settle(page)
                result = page.evaluate(OVERFLOW_JS)
                page.screenshot(path=str(OUT / f"{name}-{width}.png"), full_page=True)
                if result["scroll"] or result["bad"] or result["overlaps"]:
                    report[f"{name}@{width}"] = result
            ctx.close()
        ctx = browser.new_context(viewport={"width": width, "height": 900}, locale="ru-RU", timezone_id="Asia/Almaty")
        page = ctx.new_page()
        page.goto(BASE + "/login")
        settle(page)
        result = page.evaluate(OVERFLOW_JS)
        page.screenshot(path=str(OUT / f"login-{width}.png"), full_page=True)
        if result["scroll"] or result["bad"] or result["overlaps"]:
            report[f"login@{width}"] = result
        ctx.close()
    return report


def main():
    mode = sys.argv[1] if len(sys.argv) > 1 else "all"
    with sync_playwright() as p:
        browser = p.chromium.launch()
        report = {}
        if mode in ("all", "shots"):
            shots(browser)
        if mode in ("all", "layout"):
            report = layout(browser)
        browser.close()
    print(json.dumps(report, ensure_ascii=False, indent=1) if report else "layout OK")
    sys.exit(1 if report else 0)


main()
