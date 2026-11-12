"""Rebuild and verify the vendor assets from the source companion."""
import argparse
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
from urllib.request import urlopen


ROOT = Path(__file__).resolve().parent
SHARP_URL = "https://registry.npmjs.org/@img/sharp-win32-x64/-/sharp-win32-x64-0.33.5.tgz"
SHARP_SRI = "MpY/o8/8kj+EcnxwvrP4aTJSWw/aZ7JIGR4aBeZkZw5B7/Jn+tY9/VNwtcoGmdT7GfggGIU4kygOMSbYnOrAbg=="


def run(cwd, *command):
    subprocess.run(command, cwd=cwd, check=True)


def windows_sharp(phone):
    if sys.platform != "win32":
        return
    target = phone / "node_modules/@img/sharp-win32-x64"
    if target.exists():
        return
    with urlopen(SHARP_URL, timeout=120) as response:
        archive = response.read(12 * 1024 * 1024 + 1)
    if base64.b64encode(hashlib.sha512(archive).digest()).decode() != SHARP_SRI:
        raise ValueError("Unexpected Windows sharp package integrity")
    with tarfile.open(fileobj=io.BytesIO(archive), mode="r:gz") as tar:
        for entry in tar:
            name = entry.name
            if not name.startswith("package/") or ".." in Path(name).parts:
                raise ValueError("Unexpected native dependency archive path")
            if entry.isfile():
                file = target / name.removeprefix("package/")
                file.parent.mkdir(parents=True, exist_ok=True)
                file.write_bytes(tar.extractfile(entry).read())


def normalized_choices(raw):
    result, matches = re.subn(rb"(?<=choices\.js v11\.1\.0 \| \xc2\xa9 )\d{4}(?= Josh Johnson)", b"2025", raw)
    if matches != 1:
        raise ValueError("Unexpected Choices banner")
    return result


def normalized_phone(raw):
    name = b"  // src/js/intl-tel-input/utils.js\n"
    if raw.count(name) != 1:
        raise ValueError("Unexpected bundled utility path")
    return raw.replace(name, b"  // build/js/utils.js\n")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("destination", type=Path, help="new directory for the clean build")
    args = parser.parse_args()
    dest = args.destination.resolve()
    node = shutil.which("node")
    npm = shutil.which("npm.cmd" if os.name == "nt" else "npm")
    if not node or not npm or not shutil.which("java"):
        raise RuntimeError("Put Node, npm and Java on PATH")
    dest.mkdir(parents=True, exist_ok=False)
    for name in ("choices", "flatpickr", "phone"):
        shutil.copytree(ROOT / "vendor-source" / name, dest / name)
    choices, flatpickr, phone = (dest / name for name in ("choices", "flatpickr", "phone"))
    npm_args = ("ci", "--no-audit", "--no-fund", "--registry=https://registry.npmjs.org")
    # The native iltorb package belongs to the Choices development toolchain.
    # Neither the UMD bundle nor its CSS build loads that package.
    run(choices, npm, *npm_args, "--ignore-scripts")
    run(flatpickr, npm, *npm_args)
    run(phone, npm, *npm_args)
    windows_sharp(phone)
    run(choices, node, "node_modules/rollup/dist/bin/rollup", "--bundleConfigAsCjs", "-c",
        "scripts/rollup.config.mjs", "--environment", "WITH_D_TS_FILES:1")
    run(choices, npm, "run", "css:build")
    manifest = flatpickr / "package.json"
    package = json.loads(manifest.read_text(encoding="utf-8"))
    package["version"] = "4.6.9"  # Exact upstream npm banner for the pinned Git tag.
    manifest.write_text(json.dumps(package, indent=2), encoding="utf-8")
    run(flatpickr, node, "node_modules/ts-node/dist/bin.js", "--transpile-only", "build.ts")
    run(phone, sys.executable, str(ROOT / "generate_phone_countries.py"), str(phone),
        str(ROOT / "country-routing-policy.json"))
    run(phone, node, "node_modules/grunt-cli/bin/grunt", "closure-compiler:utils")
    shutil.copyfile(phone / "build/js/utils.js", phone / "src/js/intl-tel-input/utils.js")
    run(phone, node, "build.js")
    image_dir = phone / "build/img"
    image_dir.mkdir(parents=True, exist_ok=True)
    for globe in (phone / "src/img").glob("globe*"):
        shutil.copyfile(globe, image_dir / globe.name)
    run(phone, node, "node_modules/grunt-cli/bin/grunt", "generate-sprite", "build:css")

    choices_script = "public/assets/scripts/choices.js"
    choices_css = "public/assets/styles/choices.css"
    artifacts = {
        "choices/" + choices_script: normalized_choices((choices / choices_script).read_bytes()),
        "choices/" + choices_css: (choices / choices_css).read_bytes(),
        "flatpickr/flatpickr.min.js": (flatpickr / "dist/flatpickr.min.js").read_bytes() + b"\n",
        "flatpickr/flatpickr.min.css": b".flatpickr-calendar * { box-sizing: border-box; }\n"
        + (flatpickr / "dist/flatpickr.min.css").read_bytes() + b"\n",
        "intl-tel-input/build/js/intlTelInputWithUtils.js":
            normalized_phone((phone / "build/js/intlTelInputWithUtils.js").read_bytes()),
        "intl-tel-input/build/css/intlTelInput.css": (phone / "build/css/intlTelInput.css").read_bytes(),
    }
    for name in sorted(image_dir.iterdir()):
        artifacts["intl-tel-input/build/img/" + name.name] = name.read_bytes()
    expected = json.loads((ROOT / "asset-sha256.json").read_text(encoding="utf-8"))
    actual = {file: hashlib.sha256(data).hexdigest() for file, data in artifacts.items()}
    if actual != expected:
        differences = [key for key in sorted(actual.keys() | expected.keys())
                       if actual.get(key) != expected.get(key)]
        raise ValueError("Unexpected generated asset hashes: " + ", ".join(differences))
    for file, data in artifacts.items():
        result = dest / "verified-assets" / file
        result.parent.mkdir(parents=True, exist_ok=True)
        result.write_bytes(data)
    print(f"Verified {len(actual)} vendor assets in {dest / 'verified-assets'}")


if __name__ == "__main__":
    main()
