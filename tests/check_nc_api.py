#!/usr/bin/env python3
"""Kiểm tĩnh: mã app có còn dùng đúng API có thật trong mã nguồn Nextcloud đích không.

Cách dùng:  tests/check_nc_api.py <thư mục nextcloud/server đã lấy> [...]
Cần có lib/public, lib/private, apps/dav/lib (lấy bằng git sparse-checkout nhánh stableNN).
Lý do có tệp này: chưa có PHP/Nextcloud để chạy thật trên máy dev, nên chặn trước các lỗi
"hàm/lớp đã bị gỡ" (ví dụ Server::getLogger() mất từ bản 31) bằng cách so với mã nguồn.
Thoát mã 1 nếu có lỗi.
"""
import re
import sys
from pathlib import Path

APP = Path(__file__).resolve().parent.parent

# Phương thức app gọi vào lớp nội bộ (OC\...) -> phải còn trong lớp đó
METHODS_ON_PRIVATE = {
    "lib/private/User/Session.php": ["completeLogin", "createSessionToken", "setTokenProvider", "listen", "getSession", "prepareUserLogin"],
    "lib/private/AppFramework/Utility/ControllerMethodReflector.php": ["hasAnnotation"],
    "lib/private/legacy/OC_Util.php": ["setupFS"],
    "lib/private/legacy/OC_Template.php": ["printErrorPage"],
}


# Lớp chỉ có từ bản 32, mã đã bọc interface_exists() nên bản cũ không có là bình thường
OPTIONAL_CLASSES = {"OCP\\Template\\ITemplateManager"}


def class_file(src: Path, fqcn: str):
    fqcn = fqcn.lstrip("\\")
    if fqcn.startswith("OCP\\"):
        return src / "lib/public" / (fqcn[4:].replace("\\", "/") + ".php")
    if fqcn.startswith("OC\\"):
        return src / "lib/private" / (fqcn[3:].replace("\\", "/") + ".php")
    if fqcn.startswith("OCA\\DAV\\"):
        return src / "apps/dav/lib" / (fqcn[8:].replace("\\", "/") + ".php")
    return None


def app_files():
    return [p for p in (APP / "lib").rglob("*.php")]


def check(src: Path):
    errs = []
    # 1. mọi `use` tới OCP/OC/OCA\DAV phải còn tệp
    for f in app_files():
        for m in re.finditer(r"^use\s+([\w\\]+)(?:\s+as\s+\w+)?;", f.read_text(), re.M):
            cf = class_file(src, m.group(1))
            if cf is not None and not cf.exists():
                errs.append(f"{f.relative_to(APP)}: lớp {m.group(1)} không còn ở {src.name}")
    # 2. tham chiếu ::class ngoài `use`
    for f in app_files():
        for m in re.finditer(r"\\(OCP\\[\w\\]+|OC\\[\w\\]+)::class", f.read_text()):
            if m.group(1) in OPTIONAL_CLASSES:
                continue
            cf = class_file(src, m.group(1))
            if cf is not None and not cf.exists():
                errs.append(f"{f.relative_to(APP)}: lớp {m.group(1)} không còn")
    # 3. \OC::$server->getXxx() : Server::getXxx đã bị gỡ dần, cấm hẳn
    for f in app_files():
        for i, line in enumerate(f.read_text().splitlines(), 1):
            if line.lstrip().startswith("//"):
                continue
            if re.search(r"OC::\$server->get[A-Z]\w*\(", line):
                errs.append(f"{f.relative_to(APP)}:{i}: dùng \\OC::$server->getXxx() (đã bị gỡ ở bản 31+), hãy tiêm qua DI")
    # 4. phương thức gọi vào lớp nội bộ còn tồn tại
    for rel, names in METHODS_ON_PRIVATE.items():
        p = src / rel
        if not p.exists():
            errs.append(f"thiếu {rel} ở {src.name}")
            continue
        text = p.read_text()
        for n in names:
            if not re.search(r"function\s+" + n + r"\s*\(", text):
                errs.append(f"{rel}: không còn phương thức {n}()")
    # 5. sự kiện Sabre có kiểu còn được phát
    server = (src / "apps/dav/lib/Server.php").read_text()
    if "SabrePluginAuthInitEvent" not in server:
        errs.append("apps/dav/lib/Server.php: không còn phát SabrePluginAuthInitEvent")
    return errs


def check_info():
    errs = []
    info = (APP / "appinfo/info.xml").read_text()
    m = re.search(r'max-version="(\d+)"', info)
    if not m or int(m.group(1)) < 33:
        errs.append("appinfo/info.xml: max-version chưa tới 33")
    return errs


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(2)
    bad = check_info()
    for d in sys.argv[1:]:
        e = check(Path(d))
        print(f"{d}: {'ĐẠT' if not e else str(len(e)) + ' lỗi'}")
        bad += [f"[{d}] {x}" for x in e]
    for x in bad:
        print("  -", x)
    sys.exit(1 if bad else 0)
