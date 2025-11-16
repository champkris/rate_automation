# Azure Document Intelligence Setup Guide

## 📋 Prerequisites
- Microsoft Azure account (free trial available)
- Azure subscription

---

## 🚀 Step-by-Step Setup

### Step 1: Create Azure Document Intelligence Resource

1. **Go to Azure Portal**
   - Visit: https://portal.azure.com
   - Sign in with your Microsoft account

2. **Create Resource**
   - Click "Create a resource" (+ icon)
   - Search for "Document Intelligence" or "Form Recognizer"
   - Click "Create"

3. **Configure Resource**
   - **Subscription:** Select your subscription
   - **Resource Group:** Create new or select existing
   - **Region:** Choose closest region (e.g., East US, Southeast Asia)
   - **Name:** Choose a unique name (e.g., `rate-automation-ocr`)
   - **Pricing Tier:**
     - Free (F0): 500 pages/month, good for testing
     - Standard (S0): Pay-as-you-go, $1.50/1000 pages

4. **Review + Create**
   - Click "Review + create"
   - Wait for deployment (1-2 minutes)

### Step 2: Get API Credentials

1. **Navigate to Resource**
   - Go to "All resources"
   - Click on your Document Intelligence resource

2. **Get Keys and Endpoint**
   - In left menu, click "Keys and Endpoint"
   - You'll see:
     - **KEY 1:** (copy this)
     - **KEY 2:** (backup key)
     - **Endpoint:** https://your-resource-name.cognitiveservices.azure.com/

3. **Copy Credentials**
   - Copy KEY 1 and Endpoint

### Step 3: Configure Local Environment

1. **Edit `.env.azure` file**
   ```bash
   nano /Users/apichakriskalambasuta/Sites/localhost/rate_automation/.env.azure
   ```

2. **Add Credentials**
   ```env
   AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT=https://your-resource-name.cognitiveservices.azure.com/
   AZURE_DOCUMENT_INTELLIGENCE_KEY=your-key-here
   AZURE_DOCUMENT_MODEL=prebuilt-layout
   ```

3. **Save file** (Ctrl+O, Enter, Ctrl+X)

---

## 🧪 Test the Setup

Run the extraction script:

```bash
cd /Users/apichakriskalambasuta/Sites/localhost/rate_automation
php extract_pdfs_with_azure.php
```

### Expected Output:
```
====================================================================================================
PDF Rate Card Extraction using Azure Document Intelligence
====================================================================================================

Azure Configuration:
  Endpoint: https://your-resource.cognitiveservices.azure.com/
  API Key: abcd1234...xyz9
  Model: prebuilt-layout

Found 11 PDF files

[1/11] Processing: Rate 1st half Nov.25(2).pdf
  Analyzing: Rate 1st half Nov.25(2).pdf
  ⏳ Processing... ✓ Complete!
  📊 Found 3 table(s)
  ✓ Saved results to: Rate 1st half Nov.25(2)_tables.txt
...
```

---

## 💰 Pricing Information

### Free Tier (F0):
- **500 pages/month** free
- Perfect for testing and small projects
- **Cost:** $0

### Standard Tier (S0):
- **Pay per page:** $1.50 per 1,000 pages
- **For this project:** ~11 PDFs (estimated 50-100 pages)
  - Cost: ~$0.15 - $0.30 total

### Calculation for Rate Automation:
- 11 PDF files × ~5-10 pages each = ~55-110 pages
- Cost: **$0.08 - $0.17** (one-time)
- Monthly automation (4 emails × 15 PDFs) = ~300 pages/month
- Cost: **~$0.45/month**

---

## 🔧 Troubleshooting

### Error: "Invalid API Key"
- Check that you copied the entire key (no spaces)
- Try KEY 2 if KEY 1 doesn't work
- Regenerate keys in Azure Portal if needed

### Error: "Endpoint not found"
- Ensure endpoint ends with `.cognitiveservices.azure.com/`
- Don't include `/formrecognizer/` in endpoint URL
- Check resource is deployed successfully

### Error: "Quota exceeded"
- Free tier: Wait until next month or upgrade to S0
- Check usage in Azure Portal → Your Resource → Metrics

### Error: "Region not available"
- Try different region when creating resource
- Available regions: East US, West Europe, Southeast Asia, etc.

---

## 📊 What Happens Next

Once configured, the script will:

1. ✅ Read all 11 PDF files from temp_attachments/
2. ✅ Upload each to Azure Document Intelligence
3. ✅ Extract tables using OCR
4. ✅ Save results in JSON and readable text format
5. ✅ Convert to FCL_EXP Excel format

**Output files:**
- `azure_ocr_results/*.json` - Full Azure API responses
- `azure_ocr_results/*_tables.txt` - Extracted tables in readable format
- Final consolidated Excel file with all rates

---

## 📚 Additional Resources

- **Azure Portal:** https://portal.azure.com
- **Documentation:** https://learn.microsoft.com/azure/ai-services/document-intelligence/
- **Pricing Calculator:** https://azure.microsoft.com/pricing/calculator/
- **API Reference:** https://learn.microsoft.com/rest/api/aiservices/document-models/analyze-document

---

## ✅ Quick Start Checklist

- [ ] Created Azure account
- [ ] Created Document Intelligence resource
- [ ] Copied endpoint and key
- [ ] Updated `.env.azure` file
- [ ] Ran test: `php extract_pdfs_with_azure.php`
- [ ] Verified results in `azure_ocr_results/` folder

**Ready to extract? Let's go! 🚀**
