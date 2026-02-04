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


            <!-- Back Button -->
            <div class="text-center">
                <a href="{{ route('rate-extraction.index') }}"
                   class="inline-flex items-center px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold rounded-lg transition-colors">
                    ← Extract More Files
                </a>
            </div>
        </div>
    </div>

</body>
</html>
