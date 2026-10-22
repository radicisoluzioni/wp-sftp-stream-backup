"""UI smoke test of the admin screen with Playwright (run inside the source container network)."""
import time
from playwright.sync_api import sync_playwright

BASE = "http://localhost:8081"
OUT = "/out"

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={"width": 1280, "height": 900})
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)

    page.goto(f"{BASE}/wp-login.php")
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "admin")
    page.click("#wp-submit")
    page.wait_for_url("**/wp-admin/**")

    page.goto(f"{BASE}/wp-admin/tools.php?page=wpssb")
    page.screenshot(path=f"{OUT}/1-settings.png", full_page=True)

    page.click("#wpssb-test")
    page.wait_for_selector("#wpssb-test-result.wpssb-ok, #wpssb-test-result.wpssb-fail", timeout=30000)
    print("test result:", page.inner_text("#wpssb-test-result"))

    page.click("#wpssb-start")
    page.wait_for_selector("#wpssb-status .wpssb-badge", timeout=30000)
    time.sleep(4)
    page.screenshot(path=f"{OUT}/2-running.png", full_page=False)
    print("running status:", page.inner_text("#wpssb-status"))

    for _ in range(120):
        badge = page.inner_text("#wpssb-status .wpssb-badge")
        if badge != "Running":
            break
        time.sleep(2)
    print("final status:", page.inner_text("#wpssb-status"))
    page.click("#wpssb-list")
    page.wait_for_selector("#wpssb-remote-list table, #wpssb-remote-list p", timeout=30000)
    page.goto(f"{BASE}/wp-admin/tools.php?page=wpssb")
    page.click("#wpssb-list")
    page.wait_for_selector("#wpssb-remote-list table", timeout=30000)
    page.evaluate("document.getElementById('wpssb-log-wrap').open = true")
    page.screenshot(path=f"{OUT}/3-done.png", full_page=True)
    print("js errors:", errors)
    browser.close()
