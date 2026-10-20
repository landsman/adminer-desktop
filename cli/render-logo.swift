// Renders an SVG to a square PNG with its transparency kept (qlmanage paints it white).
// Usage: swift cli/render-logo.swift <in.svg> <out.png> <pixels>
import AppKit
let a = CommandLine.arguments, n = Int(a[3])!
let img = NSImage(contentsOf: URL(fileURLWithPath: a[1]))!
let rep = NSBitmapImageRep(bitmapDataPlanes: nil, pixelsWide: n, pixelsHigh: n, bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true, isPlanar: false, colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0)!
NSGraphicsContext.current = NSGraphicsContext(bitmapImageRep: rep)
img.draw(in: NSRect(x: 0, y: 0, width: n, height: n))
NSGraphicsContext.current = nil
try! rep.representation(using: .png, properties: [:])!.write(to: URL(fileURLWithPath: a[2]))
