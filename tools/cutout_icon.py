#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""把「白底插画」抠成透明背景图标（favicon / 页面 logo 用）。

用法：
    py -3 tools/cutout_icon.py 源图.jpg [输出.png] [尺寸]

做法与踩坑（2026-11-26 为「人生故事屋」小房子图标写的）：
1. 从四角 floodfill 只把**与纯白连通**的背景标成洋红 ——
   不能按颜色阈值一刀切：墙体是 (254,241,222)，与纯白只差 33，按阈值会连墙一起抠掉；
2. alpha 按「离纯白的距离」做软过渡（SOFT=30 往上即完全不透明），
   这样描边外侧的抗锯齿像素是半透明，不会留一圈白边；
3. 用开运算（MinFilter→MaxFilter）清掉 jpg 噪点，否则 1~3px 的孤立白点会把 bbox 撑大、
   图标四周留白变得极不均匀；
4. 以 max(宽,高)×1.05 做正方形画布居中，输出 RGBA PNG。

⚠️ numpy 里算平方距离必须用 int32/int64：int16 下 255² 会溢出成负数，sqrt 得到 NaN，
   结果是整张图 alpha 异常（这个坑踩过一次）。
"""
import sys

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

MARK = (255, 0, 255)      # 临时标记色（一般图里没有洋红，不会误伤）
THRESH = 12               # floodfill 容差：背景噪声 ≤3，墙体与白差 ~33
SOFT = 30                 # 与纯白的距离达到这个值就算完全不透明


def cutout(src, out, size):
    im = Image.open(src).convert('RGB')
    w, h = im.size

    work = im.copy()
    for pt in ((0, 0), (w - 1, 0), (0, h - 1), (w - 1, h - 1)):
        ImageDraw.floodfill(work, pt, MARK, thresh=THRESH)

    arr = np.array(im).astype(np.int32)
    bg = np.all(np.array(work) == np.array(MARK, dtype=np.uint8), axis=-1)

    d = np.sqrt(((arr - 255) ** 2).sum(-1))
    soft = np.clip(d / SOFT * 255, 0, 255)
    soft[bg] = 0

    binm = Image.fromarray((soft > 60).astype(np.uint8) * 255)
    opened = np.array(binm.filter(ImageFilter.MinFilter(5)).filter(ImageFilter.MaxFilter(5)))
    alpha = np.where(opened > 0, soft, 0)

    rgba = np.dstack([np.array(im), alpha.astype(np.uint8)])
    img = Image.fromarray(rgba, 'RGBA')

    bbox = Image.fromarray((alpha > 100).astype(np.uint8) * 255).getbbox()
    print('bbox', bbox)
    body = img.crop(bbox)
    bw, bh = body.size
    side = int(max(bw, bh) * 1.05)
    canvas = Image.new('RGBA', (side, side), (0, 0, 0, 0))
    canvas.alpha_composite(body, ((side - bw) // 2, (side - bh) // 2))

    canvas = canvas.resize((size, size), Image.LANCZOS)
    canvas.save(out, optimize=True)
    print('saved', out, canvas.size)


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)
    src = sys.argv[1]
    out = sys.argv[2] if len(sys.argv) > 2 else 'logo.png'
    size = int(sys.argv[3]) if len(sys.argv) > 3 else 256
    cutout(src, out, size)


if __name__ == '__main__':
    main()
