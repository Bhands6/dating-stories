#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""把一张「方形插画」裁成圆形站点图标。

用法：
    py -3 tools/make_logo.py 源图.jpg [输出.png] [尺寸]

裁剪逻辑（2026-11-26 为「人生故事屋」新图标写的）：
1. 以四角像素为背景色，沿中心附近的 5 条水平线 / 5 条垂直线由外向内扫，
   找到第一条与背景色欧氏距离 > TOL 的像素，取「最靠内」的那条边界
   —— 宁可少裁一点，也不要把圆边切掉；
2. 按边界裁正方形（外扩 PAD 保住 jpg 的羽化边）；
3. 再按原始半径画圆形 alpha 遮罩（4 倍超采样 + 轻模糊），
   这样外圈不会残留一圈放大的背景色；
4. 缩放到目标尺寸输出 RGBA PNG（四角全透明，可直接当 favicon）。
"""
import sys
import math

from PIL import Image, ImageDraw, ImageFilter

TOL = 45      # 与背景色的欧氏距离阈值
PAD = 1.02    # 裁剪框外扩比例
SS = 4        # 遮罩超采样倍数


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)
    src = sys.argv[1]
    out = sys.argv[2] if len(sys.argv) > 2 else 'logo.png'
    size = int(sys.argv[3]) if len(sys.argv) > 3 else 256

    im = Image.open(src).convert('RGB')
    w, h = im.size
    px = im.load()
    bg = px[3, 3]

    def dist(p):
        return math.sqrt(sum((a - b) ** 2 for a, b in zip(p, bg)))

    def edge_x(y, from_left):
        rng = range(w) if from_left else range(w - 1, -1, -1)
        for x in rng:
            if dist(px[x, y]) > TOL:
                return x
        return None

    def edge_y(x, from_top):
        rng = range(h) if from_top else range(h - 1, -1, -1)
        for y in rng:
            if dist(px[x, y]) > TOL:
                return y
        return None

    cx, cy = w // 2, h // 2
    xs_left, xs_right, ys_top, ys_bottom = [], [], [], []
    for d in (-120, -60, 0, 60, 120):
        xs_left.append(edge_x(cy + d, True))
        xs_right.append(edge_x(cy + d, False))
        ys_top.append(edge_y(cx + d, True))
        ys_bottom.append(edge_y(cx + d, False))

    x0 = max(v for v in xs_left if v is not None)
    x1 = min(v for v in xs_right if v is not None)
    y0 = max(v for v in ys_top if v is not None)
    y1 = min(v for v in ys_bottom if v is not None)
    print('edges', x0, x1, y0, y1)

    ccx, ccy = (x0 + x1) / 2, (y0 + y1) / 2
    radius = min(x1 - x0, y1 - y0) / 2
    print('center %.1f %.1f radius %.1f' % (ccx, ccy, radius))

    crop_r = radius * PAD
    box = (round(ccx - crop_r), round(ccy - crop_r), round(ccx + crop_r), round(ccy + crop_r))
    sq = im.crop(box)
    s = sq.size[0]
    print('crop size', sq.size)

    ratio = (1 / PAD) * 0.995        # 圆直径占裁剪框的比例，再收 0.5% 去掉羽化残边
    half = s * ratio / 2
    cx0, cy0 = s / 2 - half, s / 2 - half
    mask = Image.new('L', (s * SS, s * SS), 0)
    ImageDraw.Draw(mask).ellipse(
        (cx0 * SS, cy0 * SS, (cx0 + 2 * half) * SS, (cy0 + 2 * half) * SS), fill=255)
    mask = mask.resize((s, s), Image.LANCZOS).filter(ImageFilter.GaussianBlur(0.6))

    out_im = sq.convert('RGBA')
    out_im.putalpha(mask)
    out_im = out_im.resize((size, size), Image.LANCZOS)
    out_im.save(out, optimize=True)
    print('saved', out, out_im.size)


if __name__ == '__main__':
    main()
