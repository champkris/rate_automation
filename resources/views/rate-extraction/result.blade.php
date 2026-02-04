<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Card Extraction - Results</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">Extraction Complete</h1>
                <p class="text-gray-600 mt-2">Your rate card files have been processed</p>
            </div>

            @php
                $successFiles = collect($batchFiles)->where('status', 'success');
                $failedFiles = collect($batchFiles)->where('status', 'failed');
                $successCount = $successFiles->count();
                $failedCount = $failedFiles->count();
                $totalCount = count($batchFiles);
            @endphp

            <!-- Summary Stats -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-md p-6 text-center">
                    <p class="text-4xl font-bold text-blue-600">{{ $totalCount }}</p>
                    <p class="text-sm text-gray-600 mt-1">Total Files</p>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6 text-center">
                    <p class="text-4xl font-bold text-green-600">{{ $successCount }}</p>
                    <p class="text-sm text-gray-600 mt-1">Successful</p>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6 text-center">
                    <p class="text-4xl font-bold text-red-600">{{ $failedCount }}</p>
                    <p class="text-sm text-gray-600 mt-1">Failed</p>
                </div>
            </div>

            <!-- File Results -->
            <div class="space-y-4 mb-6">
                @foreach($batchFiles as $index => $file)
                <div class="bg-white rounded-lg shadow-md p-6">
                    @if($file['status'] === 'success')
                        <!-- Success -->
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="bg-green-100 rounded-full p-2">
                                    <svg class="h-8 w-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4 flex-1">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-800">{{ $file['original_filename'] }}</h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <span class="font-medium">→ {{ $file['download_name'] }}</span>
                                        </p>
                                        <div class="flex items-center gap-4 mt-2 text-sm text-gray-500">
                                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded">{{ $file['carrier'] }}</span>
                                            <span>📅 {{ $file['validity'] }}</span>
                                            <span>📊 {{ $file['rate_count'] }} rates</span>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="{{ route('rate-extraction.download', $file['output_filename']) }}"
                                           download="{{ $file['download_name'] }}"
                                           class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors">
                                            <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                            </svg>
                                            Download
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <!-- Failed -->
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="bg-red-100 rounded-full p-2">
                                    <svg class="h-8 w-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4 flex-1">
                                <h3 class="text-lg font-semibold text-gray-800">{{ $file['original_filename'] }}</h3>
                                <p class="text-sm text-red-600 mt-1">
                                    <span class="font-medium">Error:</span> {{ $file['error'] }}
                                </p>

                                <!-- Manual pattern selection -->
                                <div class="mt-4 p-4 bg-orange-50 rounded-lg">
                                    <p class="text-sm font-medium text-gray-700 mb-2">Try re-processing with a specific pattern:</p>
                                    <form action="{{ route('rate-extraction.reprocess') }}" method="POST" class="flex items-end gap-3">
                                        @csrf
                                        <input type="hidden" name="filename" value="{{ $file['temp_filename'] }}">
                                        <div class="flex-1">
                                            <label class="block text-xs text-gray-600 mb-1">Select Carrier Pattern</label>
                                            <select name="pattern" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                                @foreach(session('batch_patterns', []) as $key => $label)
                                                    @if($key !== 'auto')
                                                    <option value="{{ $key }}">{{ $label }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        </div>
                                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                                            Re-process
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
                @endforeach
            </div>

            <!-- Download All Section -->
            @if($successCount > 0)
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="text-center">
                    <button id="downloadAllBtn" onclick="downloadAll()" class="inline-flex items-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg transition-colors">
                        <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                        📥 Download All Files ({{ $successCount }})
                    </button>

                    <!-- Fallback message (hidden initially) -->
                    <div id="fallbackMessage" class="hidden mt-6">
                        <div class="bg-orange-100 border border-orange-400 text-orange-700 px-6 py-4 rounded-lg">
                            <p class="font-bold text-lg">Browser ไม่ support Download All</p>
                            <p class="mt-1">รบกวนกด Download ทีละไฟล์นะครับ 👇</p>
                        </div>
                    </div>

                    <!-- Individual downloads (hidden initially) -->
                    <div id="individualDownloads" class="hidden mt-6 space-y-2">
                        @foreach($batchFiles as $file)
                            @if($file['status'] === 'success')
                            <a href="{{ route('rate-extraction.download', $file['output_filename']) }}"
                               download="{{ $file['download_name'] }}"
                               class="block bg-gray-50 hover:bg-gray-100 border border-gray-200 px-4 py-3 rounded-lg transition-colors">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-gray-700">📄 {{ $file['download_name'] }}</span>
                                    <span class="text-sm text-gray-500">{{ $file['rate_count'] }} rates</span>
                                </div>
                            </a>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <!-- Back Button -->
            <div class="text-center">
                <a href="{{ route('rate-extraction.index') }}"
                   class="inline-flex items-center px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold rounded-lg transition-colors">
                    ← Extract More Files
                </a>
            </div>
        </div>
    </div>

    <script>
        const successFiles = @json($successFiles->values());

        async function downloadAll() {
            if ('showDirectoryPicker' in window) {
                // File System Access API (Chrome/Edge)
                try {
                    const directoryHandle = await window.showDirectoryPicker();

                    let successCount = 0;
                    for (const file of successFiles) {
                        try {
                            const response = await fetch(`/extract/download/${file.output_filename}`);
                            if (!response.ok) throw new Error('Download failed');

                            const blob = await response.blob();
                            const fileHandle = await directoryHandle.getFileHandle(file.download_name, {create: true});
                            const writable = await fileHandle.createWritable();
                            await writable.write(blob);
                            await writable.close();

                            successCount++;
                        } catch (err) {
                            console.error('Failed to download:', file.download_name, err);
                        }
                    }

                    alert(`✅ ดาวน์โหลดไฟล์ทั้งหมดเรียบร้อยแล้ว! (${successCount}/${successFiles.length} ไฟล์)`);
                } catch (err) {
                    if (err.name === 'AbortError') {
                        return; // User cancelled
                    }
                    console.error('Error:', err);
                    alert('เกิดข้อผิดพลาด: ' + err.message);
                }
            } else {
                // Fallback for Firefox/Safari
                document.getElementById('downloadAllBtn').classList.add('hidden');
                document.getElementById('fallbackMessage').classList.remove('hidden');
                document.getElementById('individualDownloads').classList.remove('hidden');
            }
        }
    </script>
</body>
</html>
