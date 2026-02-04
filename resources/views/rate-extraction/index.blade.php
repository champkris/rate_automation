<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Card Extraction - Upload</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-2xl mx-auto">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">Rate Card Extraction</h1>
                <p class="text-gray-600 mt-2">Upload a rate card file and select the extraction pattern</p>
            </div>

            <!-- Error Message -->
            @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6" role="alert">
                <span class="block sm:inline">{{ session('error') }}</span>
            </div>
            @endif

            <!-- Success Message -->
            @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6" role="alert">
                <span class="block sm:inline">{{ session('success') }}</span>
            </div>
            @endif

            <!-- Upload Form -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <form action="{{ route('rate-extraction.process') }}" method="POST" enctype="multipart/form-data" id="uploadForm">
                    @csrf

                    <!-- File Upload -->
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="rate_files">
                            Rate Card Files (Max 15 files)
                        </label>
                        <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-blue-500 transition-colors cursor-pointer" id="dropZone">
                            <input type="file" name="rate_files[]" id="rate_files" accept=".xlsx,.xls,.csv,.pdf" class="hidden" multiple required>
                            <div id="fileInfo">
                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <p class="mt-2 text-sm text-gray-600">
                                    <span class="font-medium text-blue-600 hover:text-blue-500">Click to upload</span> or drag and drop
                                </p>
                                <p class="mt-1 text-xs text-gray-500">Excel (.xlsx, .xls), CSV, or PDF files (max 10MB each, max 15 files)</p>
                            </div>
                            <div id="selectedFiles" class="hidden">
                                <svg class="mx-auto h-12 w-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <p class="mt-2 text-sm font-medium text-gray-900" id="fileCount"></p>
                                <div id="fileList" class="mt-2 text-xs text-left max-h-40 overflow-y-auto"></div>
                            </div>
                        </div>
                        @error('rate_files')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                        @error('rate_files.*')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Pattern Selection -->
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="pattern">
                            Extraction Pattern
                        </label>
                        <select name="pattern" id="pattern" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                            @foreach($patterns as $key => $label)
                            <option value="{{ $key }}" {{ $key === 'auto' ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Select "Auto-detect" to automatically detect the carrier from filename</p>
                        @error('pattern')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Validity Date -->
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="validity">
                            Validity Period (Optional)
                        </label>
                        <input type="text" name="validity" id="validity" placeholder="e.g., DEC 2025 or 01-31 Dec 2025"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <p class="text-xs text-gray-500 mt-1">Leave empty to use current month or extract from file</p>
                    </div>

                    <!-- Progress Bar (hidden initially) -->
                    <div id="progressContainer" class="hidden mb-6">
                        <div class="bg-gray-200 rounded-full h-8 overflow-hidden">
                            <div id="progressBar" class="bg-blue-600 h-8 transition-all duration-300 flex items-center justify-center" style="width: 0%">
                                <span id="progressText" class="text-white font-bold text-sm"></span>
                            </div>
                        </div>
                        <p class="text-center text-sm text-gray-600 mt-2">Processing your files... Please wait</p>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex items-center justify-between">
                        <button type="submit" id="submitBtn" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                            <span id="btnText">Extract Rates</span>
                            <span id="btnLoading" class="hidden">
                                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Processing...
                            </span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Pattern Guide -->
            <div class="mt-8 bg-white rounded-lg shadow-md p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4">Supported Carriers</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <div class="text-sm"><span class="font-medium">RCL</span> - FAK Rate</div>
                    <div class="text-sm"><span class="font-medium">KMTC</span> - Updated Rate</div>
                    <div class="text-sm"><span class="font-medium">SINOKOR</span> - Main Rate Card</div>
                    <div class="text-sm"><span class="font-medium">SINOKOR SKR</span> - HK Feederage</div>
                    <div class="text-sm"><span class="font-medium">HEUNG A</span></div>
                    <div class="text-sm"><span class="font-medium">BOXMAN</span></div>
                    <div class="text-sm"><span class="font-medium">SITC</span></div>
                    <div class="text-sm"><span class="font-medium">WANHAI</span> - India Rate</div>
                    <div class="text-sm"><span class="font-medium">CK LINE</span></div>
                    <div class="text-sm"><span class="font-medium">SM LINE</span></div>
                    <div class="text-sm"><span class="font-medium">DONGJIN</span></div>
                    <div class="text-sm"><span class="font-medium">TS LINE</span></div>
                    <div class="text-sm"><span class="font-medium">PIL</span> - Africa</div>
                    <div class="text-sm"><span class="font-medium">PIL</span> - Intra Asia</div>
                    <div class="text-sm"><span class="font-medium">PIL</span> - Latin America</div>
                    <div class="text-sm"><span class="font-medium">PIL</span> - Oceania</div>
                </div>
                <p class="text-xs text-gray-500 mt-4">
                    <strong>Note:</strong> PDF files require pre-processed Azure OCR results. Excel files (.xlsx, .xls) are processed directly.
                </p>
            </div>
        </div>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('rate_files');
        const fileInfo = document.getElementById('fileInfo');
        const selectedFiles = document.getElementById('selectedFiles');
        const fileCount = document.getElementById('fileCount');
        const fileList = document.getElementById('fileList');
        const uploadForm = document.getElementById('uploadForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const btnLoading = document.getElementById('btnLoading');

        // Click to upload
        dropZone.addEventListener('click', () => fileInput.click());

        // Drag and drop
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('border-blue-500', 'bg-blue-50');
        });

        dropZone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            dropZone.classList.remove('border-blue-500', 'bg-blue-50');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('border-blue-500', 'bg-blue-50');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                updateFileDisplay();
            }
        });

        // File selection
        fileInput.addEventListener('change', updateFileDisplay);

        function updateFileDisplay() {
            const files = fileInput.files;

            if (files.length > 0) {
                // Validate max 15 files
                if (files.length > 15) {
                    alert('Maximum 15 files allowed. Please select fewer files.');
                    fileInput.value = '';
                    fileInfo.classList.remove('hidden');
                    selectedFiles.classList.add('hidden');
                    return;
                }

                // Count file types for progress estimation
                let pdfCount = 0;
                let excelCount = 0;
                let totalSize = 0;

                // Build file list HTML
                let listHtml = '<div class="space-y-1">';

                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const ext = file.name.split('.').pop().toLowerCase();

                    // Count by type
                    if (ext === 'pdf') {
                        pdfCount++;
                    } else if (['xlsx', 'xls', 'csv'].includes(ext)) {
                        excelCount++;
                    }

                    totalSize += file.size;

                    // Add to list
                    const icon = ext === 'pdf' ? '📄' : '📊';
                    listHtml += `<div class="flex items-center justify-between px-2 py-1 bg-gray-50 rounded">
                        <span class="text-gray-700">${icon} ${file.name}</span>
                        <span class="text-gray-500">${formatFileSize(file.size)}</span>
                    </div>`;
                }

                listHtml += '</div>';

                // Update display
                fileCount.textContent = `${files.length} file${files.length > 1 ? 's' : ''} selected (${pdfCount} PDF, ${excelCount} Excel) - Total: ${formatFileSize(totalSize)}`;
                fileList.innerHTML = listHtml;

                fileInfo.classList.add('hidden');
                selectedFiles.classList.remove('hidden');

                // Store file counts in form data for progress calculation
                sessionStorage.setItem('fileUploadCounts', JSON.stringify({
                    pdfCount: pdfCount,
                    excelCount: excelCount
                }));
            } else {
                fileInfo.classList.remove('hidden');
                selectedFiles.classList.add('hidden');
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Form submission with progress bar
        let progressInterval;

        uploadForm.addEventListener('submit', function(e) {
            // Validate that files are selected
            if (!fileInput.files || fileInput.files.length === 0) {
                alert('Please select at least one file to upload.');
                e.preventDefault();
                return false;
            }

            // Get file counts from sessionStorage
            const counts = JSON.parse(sessionStorage.getItem('fileUploadCounts') || '{"pdfCount":0,"excelCount":0}');
            const pdfCount = counts.pdfCount || 0;
            const excelCount = counts.excelCount || 0;

            // Calculate total estimated time (PDF=9s, Excel=4s)
            const totalTime = (pdfCount * 9 + excelCount * 4) * 1000; // milliseconds

            // Disable submit button and show loading
            submitBtn.disabled = true;
            btnText.classList.add('hidden');
            btnLoading.classList.remove('hidden');

            // Show progress bar
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');

            progressContainer.classList.remove('hidden');

            // Animate progress bar to 95% over estimated time
            let progress = 0;
            const maxProgress = 95;
            const updateInterval = 100; // Update every 100ms
            const increment = (maxProgress / totalTime) * updateInterval;

            progressInterval = setInterval(() => {
                progress += increment;

                if (progress >= maxProgress) {
                    progress = maxProgress;
                    clearInterval(progressInterval);
                }

                progressBar.style.width = Math.floor(progress) + '%';
                progressText.textContent = Math.floor(progress) + '%';
            }, updateInterval);

            // Allow form to submit normally
            return true;
        });
    </script>
</body>
</html>
