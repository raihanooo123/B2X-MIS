/**
 * 05.1 §8.2 — scan a shelf barcode with the device camera. The in-aisle
 * restocking workflow: point the phone at the shelf label, and the pad
 * searches for that barcode, focuses the row and preselects the pack.
 *
 * Uses the browser's own BarcodeDetector (no scanning library is approved
 * in the stack). Where the browser lacks it, the camera button is not
 * shown at all; a handheld scanner still works everywhere, because it
 * types the code into the search box and presses Enter.
 *
 * The camera stream is stopped whenever the dialog closes, on a match, or
 * when the page unmounts — never left running in the background.
 */
import { Loader2, ScanBarcode } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

/** Shelf and case labels: EAN/UPC on retail goods, Code 128/39 and ITF on outers. */
const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf'];
const SCAN_INTERVAL_MS = 200;

interface DetectedBarcode {
    rawValue: string;
}
interface BarcodeDetectorLike {
    detect(source: CanvasImageSource): Promise<DetectedBarcode[]>;
}
type BarcodeDetectorClass = new (options?: { formats?: string[] }) => BarcodeDetectorLike;

function detectorClass(): BarcodeDetectorClass | null {
    if (typeof window === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
        return null;
    }
    const ctor = (window as unknown as { BarcodeDetector?: BarcodeDetectorClass }).BarcodeDetector;

    return ctor ?? null;
}

/** True when this browser can scan with the camera. */
export function canScanWithCamera(): boolean {
    return detectorClass() !== null;
}

type Status = 'starting' | 'scanning' | 'denied' | 'failed';

export function BarcodeScanButton({ onCode }: { onCode: (code: string) => void }) {
    const [supported, setSupported] = useState(false);
    const [open, setOpen] = useState(false);

    // Checked after mount: the server render has no camera.
    useEffect(() => setSupported(canScanWithCamera()), []);

    if (!supported) {
        return null;
    }

    return (
        <>
            <Button type="button" variant="outline" className="h-11 md:h-9" onClick={() => setOpen(true)}>
                <ScanBarcode aria-hidden /> Scan
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Scan a barcode</DialogTitle>
                        <DialogDescription>Point the camera at the shelf label or the case barcode.</DialogDescription>
                    </DialogHeader>
                    {open && (
                        <CameraView
                            onCode={(code) => {
                                setOpen(false);
                                onCode(code);
                            }}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function CameraView({ onCode }: { onCode: (code: string) => void }) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const [status, setStatus] = useState<Status>('starting');
    const onCodeRef = useRef(onCode);
    onCodeRef.current = onCode;

    useEffect(() => {
        const Detector = detectorClass();
        if (Detector === null) {
            setStatus('failed');
            return;
        }
        let stream: MediaStream | null = null;
        let timer: number | undefined;
        let stopped = false;
        const detector = new Detector({ formats: FORMATS });

        const stop = () => {
            stopped = true;
            window.clearTimeout(timer);
            stream?.getTracks().forEach((t) => t.stop());
        };

        const tick = async () => {
            const video = videoRef.current;
            if (stopped || video === null) {
                return;
            }
            if (video.readyState >= 2) {
                try {
                    const found = (await detector.detect(video)).find((b) => b.rawValue.trim() !== '');
                    if (found && !stopped) {
                        stop();
                        onCodeRef.current(found.rawValue.trim());
                        return;
                    }
                } catch {
                    // A frame the detector could not read: try the next one.
                }
            }
            timer = window.setTimeout(() => void tick(), SCAN_INTERVAL_MS);
        };

        navigator.mediaDevices
            .getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
            .then(async (s) => {
                if (stopped) {
                    s.getTracks().forEach((t) => t.stop());
                    return;
                }
                stream = s;
                const video = videoRef.current;
                if (video !== null) {
                    video.srcObject = s;
                    await video.play().catch(() => undefined);
                }
                setStatus('scanning');
                void tick();
            })
            .catch((err: unknown) => {
                setStatus(err instanceof DOMException && (err.name === 'NotAllowedError' || err.name === 'SecurityError') ? 'denied' : 'failed');
            });

        return stop;
    }, []);

    return (
        <div className="flex flex-col gap-3">
            <div className="relative aspect-[4/3] overflow-hidden rounded-lg bg-black">
                <video ref={videoRef} className="size-full object-cover" muted playsInline aria-label="Camera view" />
                {status === 'scanning' && <div className="pointer-events-none absolute inset-x-8 top-1/2 h-0.5 -translate-y-1/2 bg-red-500/80" aria-hidden />}
                {status === 'starting' && (
                    <div className="absolute inset-0 flex items-center justify-center text-sm text-white">
                        <Loader2 className="mr-2 size-4 animate-spin" aria-hidden /> Starting the camera…
                    </div>
                )}
            </div>
            <p role="status" className="text-sm text-muted-foreground">
                {status === 'scanning' && 'Looking for a barcode…'}
                {status === 'denied' && 'The camera is blocked. Allow camera access for this site in your browser settings, or type the barcode into the search box.'}
                {status === 'failed' && 'The camera could not be started. Type the barcode into the search box instead.'}
            </p>
        </div>
    );
}
