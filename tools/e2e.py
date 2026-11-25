import io
import json
import re
import subprocess
import sys
import tempfile
import urllib.request
import zipfile
from datetime import date, timedelta
from pathlib import Path
from xml.etree import ElementTree

from playwright.sync_api import expect, sync_playwright

BASE = "http://127.0.0.1:8311"
MAILPIT = "http://127.0.0.1:8313"
OUT = Path(__file__).resolve().parent / "out" / "e2e"
W1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]
W2 = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2]


def ok(msg):
    print("OK  " + msg)


def fail(msg):
    print("FAIL " + msg)
    sys.exit(1)


def check(cond, msg):
    if not cond:
        fail(msg)
    ok(msg)


def make_iin(birth: date, male: bool, serial: int) -> str:
    century = 3 if birth.year < 2000 else 5
    first = birth.strftime("%y%m%d") + str(century + (0 if male else 1)) + f"{serial:04d}"
    d = [int(c) for c in first]
    c = sum(a * b for a, b in zip(d, W1)) % 11
    if c == 10:
        c = sum(a * b for a, b in zip(d, W2)) % 11
        if c == 10:
            return make_iin(birth, male, serial + 1)
    return first + str(c)


class Api:
    def __init__(self, ctx, token=None):
        self.ctx = ctx
        self.token = token

    def headers(self):
        h = {"Accept": "application/json"}
        if self.token:
            h["Authorization"] = "Bearer " + self.token
        return h

    def login(self, email):
        r = self.ctx.post(BASE + "/api/auth/login", data={"email": email, "password": "portal2026"}, headers={"Accept": "application/json"})
        assert r.ok, r.text()
        self.token = r.json()["token"]
        return self

    def get(self, path):
        r = self.ctx.get(BASE + "/api" + path, headers=self.headers())
        assert r.ok, f"{path}: {r.status} {r.text()[:300]}"
        return r

    def post(self, path, data=None, multipart=None, expect_status=None):
        r = self.ctx.post(BASE + "/api" + path, data=data, multipart=multipart, headers=self.headers())
        if expect_status:
            assert r.status == expect_status, f"{path}: {r.status} {r.text()[:300]}"
        else:
            assert r.ok, f"{path}: {r.status} {r.text()[:300]}"
        return r


def xlsx_strings(data: bytes):
    z = zipfile.ZipFile(io.BytesIO(data))
    ns = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
    shared = []
    if "xl/sharedStrings.xml" in z.namelist():
        root = ElementTree.fromstring(z.read("xl/sharedStrings.xml"))
        for si in root.findall("m:si", ns):
            shared.append("".join(t.text or "" for t in si.iter("{%s}t" % ns["m"])))
    texts = list(shared)
    for name in z.namelist():
        if name.startswith("xl/worksheets/sheet"):
            root = ElementTree.fromstring(z.read(name))
            for t in root.iter("{%s}t" % ns["m"]):
                texts.append(t.text or "")
    return texts


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    today = date.today()
    with sync_playwright() as p:
        browser = p.chromium.launch()
        req_ctx = p.request.new_context()
        head = Api(req_ctx).login("head@ertis.test")
        ok("вход руководителя по API")

        types = {t["code"]: t for t in head.get("/document-types").json()["data"]}
        profiles = head.get("/profiles").json()["data"]
        gres = next(x for x in profiles if x["name"].startswith("ГРЭС"))
        customers = head.get("/customers").json()["data"]
        gres_customer = next(c for c in customers if c["short_name"] == "ГРЭС-1")
        scan_bytes = head.get("/documents/1/scan").body()
        check(scan_bytes[:3] == b"\xff\xd8\xff", "скан-заглушка из начальных данных отдаётся как JPEG")
        (OUT / "sample-scan.jpg").write_bytes(scan_bytes)

        iin = make_iin(date(1991, 8, 17), True, 4321)
        ctx = browser.new_context(viewport={"width": 1440, "height": 900}, locale="ru-RU", timezone_id="Asia/Almaty", accept_downloads=True)
        page = ctx.new_page()
        console_errors = []
        page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
        page.goto(BASE + "/login")
        page.fill("#email", "ot@ertis.test")
        page.fill("#password", "portal2026")
        page.click("button[type=submit]")
        page.wait_for_url(BASE + "/")
        expect(page.get_by_role("heading", name="Кто может выйти завтра")).to_be_visible()
        ok("вход специалиста по ОТ через форму, сводка открылась")

        page.goto(BASE + "/workers")
        page.click("text=Добавить работника")
        page.fill("#wf-last", "Рахимов")
        page.fill("#wf-first", "Талгат")
        page.fill("#wf-middle", "Ерланович")
        page.fill("#wf-iin", iin[:11] + str((int(iin[11]) + 1) % 10))
        expect(page.locator("text=ИИН не прошёл проверку контрольной суммы")).to_be_visible()
        ok("неверный ИИН подсвечен на форме")
        page.fill("#wf-iin", iin)
        expect(page.locator("text=Дата рождения по ИИН: 17.08.1991")).to_be_visible()
        page.fill("#wf-pos", "Электромонтажник")
        page.select_option("#wf-grade", "5")
        page.fill("#wf-site", "Электромонтажный участок")
        page.click("button[form=worker-form]")
        page.wait_for_url(re.compile(r"/workers/\d+$"))
        worker_id = int(page.url.rsplit("/", 1)[1])
        expect(page.locator(".head-info .badge").first).to_have_text("Не готов")
        ok(f"работник создан через форму (id {worker_id}), светофор: не готов")

        def add_doc(code, until=None, issued=None, group=None, scan=True):
            page.click("text=Добавить документ")
            page.select_option("#df-type", str(types[code]["id"]))
            page.fill("#df-number", code.upper() + "-77")
            if issued:
                page.fill("#df-issued", issued.isoformat())
            if until:
                page.fill("#df-until", until.isoformat())
            if group:
                page.select_option("#df-group", str(group))
            if scan:
                page.set_input_files("#df-scan", str(OUT / "sample-scan.jpg"))
            page.click("button[form=doc-form]")
            expect(page.locator(".dialog")).to_have_count(0)

        add_doc("med", issued=today - timedelta(days=60))
        add_doc("intro", issued=today - timedelta(days=30))
        add_doc("ot", until=today + timedelta(days=10))
        add_doc("ppe", until=today + timedelta(days=200))
        badge = page.locator(".head-info .badge").first
        expect(badge).to_have_text(re.compile("Истекает через 10 дней"))
        ok("после 4 документов светофор жёлтый: «Истекает через 10 дней»")
        add_doc("ot", until=today + timedelta(days=365))
        expect(badge).to_have_text("Готов")
        ok("после продления ОТ светофор зелёный: «Готов»")
        for code, kw in (("repeat", {"until": today + timedelta(days=150)}), ("pb", {"until": today + timedelta(days=300)}),
                         ("eb", {"until": today + timedelta(days=280), "group": 4}), ("height", {"until": today + timedelta(days=250)}),
                         ("first_aid", {"until": today + timedelta(days=12)})):
            add_doc(code, **kw)
        card = head.get(f"/workers/{worker_id}").json()
        check(len(card["documents"]) == 10 and all(d["has_scan"] for d in card["documents"]), "10 документов со сканами загружены через форму")
        page.screenshot(path=str(OUT / "worker-card.png"), full_page=True)

        ready_ids = [w["id"] for w in head.get("/workers?search=Петренко").json()["data"]]
        start = today + timedelta(days=3)
        r = head.post("/requests", data={
            "customer_id": gres_customer["id"],
            "facility_id": gres_customer["facilities"][0]["id"],
            "requirement_profile_id": gres["id"],
            "start_date": start.isoformat(),
            "end_date": (start + timedelta(days=20)).isoformat(),
            "worker_ids": [worker_id] + ready_ids,
        }).json()
        rid = r["id"]
        check(r["readiness"]["status"] == "expiring", f"заявка {r['number']}: срок первой помощи кончается в период работ, статус «истекает»")

        page.goto(BASE + f"/requests/{rid}")
        expect(page.locator("h1", has_text=r["number"])).to_be_visible()
        page.click("button:has-text('Подписать ЭЦП')")
        expect(page.locator(".sign-data")).to_contain_text(iin)
        page.click(".dialog-foot button:has-text('Подписать')")
        expect(page.locator(".badge", has_text="действительна")).to_be_visible()
        ok("список подписан в режиме эмуляции через интерфейс")
        page.click("button:has-text('Подать заказчику')")
        expect(page.locator(".page-head .badge", has_text="Подана заказчику")).to_be_visible()
        ok("заявка подана заказчику")
        with page.expect_download() as dl:
            page.click("button:has-text('Пакет допуска ZIP')")
        zpath = OUT / "package.zip"
        dl.value.save_as(zpath)
        with page.expect_download() as dl2:
            page.click("button:has-text('Список на пропуск xlsx')")
        xpath = OUT / "pass-list.xlsx"
        dl2.value.save_as(xpath)
        page.screenshot(path=str(OUT / "request.png"), full_page=True)

        z = zipfile.ZipFile(zpath)
        names = z.namelist()
        number = r["number"]
        check(f"00_Список_на_допуск_{number}.pdf" in names, "в ZIP есть сводный PDF")
        check(f"00_Список_на_пропуск_{number}.xlsx" in names, "в ZIP есть xlsx на пропуск")
        check(f"00_Подпись/{number}.cms" in names, "в ZIP есть файл подписи CMS")
        folder = next(n.split("/")[0] for n in names if n.endswith("/") and "Рахимов" in n)
        scans = [n for n in names if n.startswith(folder + "/") and n.endswith(".jpg")]
        check(len(scans) == len(gres["items"]), f"в папке {folder} {len(scans)} сканов по профилю ГРЭС")
        signed = json.loads(z.read("00_Подпись/подписанные_данные.json"))
        check(any(w["iin"] == iin for w in signed["workers"]), "подписанные данные содержат ИИН нового работника")
        pdf_path = OUT / "list.pdf"
        pdf_path.write_bytes(z.read(f"00_Список_на_допуск_{number}.pdf"))
        text = subprocess.run(["pdftotext", "-layout", str(pdf_path), "-"], capture_output=True, text=True).stdout
        check("Рахимов Талгат" in text and iin in text, "PDF содержит ФИО и ИИН")
        check("Список подписан ЭЦП" in text, "PDF содержит отметку о подписи")
        check("Первая помощь" in text and "ЭБ гр. IV" in text, "PDF содержит документы со сроками и группой ЭБ")
        subprocess.run(["pdftoppm", "-r", "70", "-png", "-f", "1", "-l", "1", str(pdf_path), str(OUT / "list")], check=True)
        strings = xlsx_strings(xpath.read_bytes())
        check(iin in strings and "Рахимов" in strings, "xlsx на пропуск читается и содержит работника")
        reg = xlsx_strings(head.get("/exports/registry").body())
        check("просрочен" in reg and "истекает" in reg, "реестр сроков xlsx содержит просроченные и истекающие")

        before = json.load(urllib.request.urlopen(MAILPIT + "/api/v1/messages"))["messages_count"]
        run = head.post("/notifications/run").json()
        check(run["created"] > 0, f"проверка сроков создала {run['created']} сообщений, документов в сводке {run['items']}")
        import time
        mails = []
        for _ in range(30):
            data = json.load(urllib.request.urlopen(MAILPIT + "/api/v1/messages"))
            mails = [m for m in data["messages"] if m["Subject"].startswith("Сроки документов")]
            if data["messages_count"] > before:
                break
            time.sleep(1)
        check(data["messages_count"] > before and mails, f"письмо пришло в Mailpit: «{mails[0]['Subject'] if mails else ''}»")
        msg = json.load(urllib.request.urlopen(MAILPIT + f"/api/v1/message/{mails[0]['ID']}"))
        check("Рахимов Талгат" in msg["Text"] or "Рахимов Талгат" in msg["HTML"], "в письме есть работник с истекающей первой помощью")
        journal = head.get("/notifications").json()["data"]
        tg = next(n for n in journal if n["channel"] == "telegram")
        check(tg["status"] in ("logged", "sent"), f"Telegram в журнале: {tg['status']} ({tg['error'] or 'отправлено'})")
        page.goto(BASE + "/journal")
        expect(page.locator(".log-head").first).to_be_visible()
        page.screenshot(path=str(OUT / "journal.png"))

        cust = browser.new_context(viewport={"width": 1440, "height": 900}, locale="ru-RU", timezone_id="Asia/Almaty")
        cp = cust.new_page()
        cp.goto(BASE + "/login")
        cp.fill("#email", "ot.gres1@zakazchik.test")
        cp.fill("#password", "portal2026")
        cp.click("button[type=submit]")
        cp.wait_for_url(BASE + "/customer")
        cp.click(f"table >> text={number}")
        expect(cp.locator("h1", has_text=number)).to_be_visible()
        cp.locator(".remark-row input[type=checkbox]").first.check()
        cp.locator(".remark-row input.input").first.fill("скан медосмотра плохо читается")
        cp.click("button:has-text('Вернуть с замечаниями')")
        expect(cp.locator(".alert.tone-danger", has_text="Опишите замечания")).to_be_visible()
        ok("без комментария замечания не отправляются")
        cp.fill("#c-comment", "Замените скан медосмотра")
        cp.click("button:has-text('Вернуть с замечаниями')")
        expect(cp.locator(".page-head .badge", has_text="Замечания")).to_be_visible()
        ok("заказчик вернул заявку с замечаниями")

        page.goto(BASE + f"/requests/{rid}")
        expect(page.locator(".alert", has_text="Замените скан медосмотра")).to_be_visible()
        page.click("button:has-text('Подать заказчику')")
        expect(page.locator(".page-head .badge", has_text="Подана заказчику")).to_be_visible()
        ok("подрядчик видит замечание и подаёт повторно")
        cp.reload()
        cp.click("button:has-text('Допустить')")
        expect(cp.locator(".page-head .badge", has_text="Допущены")).to_be_visible()
        ok("заказчик допустил работников")
        cp.screenshot(path=str(OUT / "customer.png"), full_page=True)

        errors = [e for e in console_errors if "favicon" not in e]
        check(not errors, "в консоли браузера подрядчика нет ошибок" + (": " + "; ".join(errors[:3]) if errors else ""))
        browser.close()
    print("E2E OK")


main()
