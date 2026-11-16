# PROJECT AUDIT: Kisan Seva Krishi Kendra - Agriculture Store & Billing Web App

**Date:** 2026-11-16  
**Auditor:** Senior Open-Source Maintainer & DevSecOps Engineer  
**Project Path:** `C:\Users\75098\.gemini\antigravity\scratch\haryali-krishi-kendra`  
**Target Repository:** `jagmohan-kurmi/haryali-krishi-kendra`  
**Open-Source Program:** OpenAI Codex for Open Source  

---

## 1. Executive Summary

Kisan Seva Krishi Kendra is an agricultural retail, farmer billing, and inventory management web platform built for fertilizer, certified seed, and pesticide stores (Krishi Seva Kendras). It provides retail cash/credit invoicing, IFFCO fertilizer distribution records, live local mandi bhav tickers, farmer credit khata ledgers, and inventory reorder alerts.

---

## 2. Architecture & Tech Stack

| Layer | Technology | Details |
|---|---|---|
| **Frontend UI** | HTML5, CSS3, Vanilla ES6+ | Lightweight zero-build PWA architecture with service worker |
| **Backend & Mock API** | Node.js / Express (`server.js`) | Local JSON-backed REST API for sales, inventory, and ledger |
| **Storage** | Mock JSON Datastores | `mock_products.json`, `mock_sales.json`, `mock_customers.json` |
| **PWA & Offline** | `sw.js` & `manifest.json` | Full offline availability at remote rural village locations |

---

## 3. Core Modules

1. **Retail Billing & GST Invoicing:** Cash memo and tax invoice generator formatted for thermal and A4 printers.
2. **Fertilizer & Seed Catalog:** Urea, DAP, NPK, hybrid seeds, and pesticide inventory with batch numbers.
3. **Kisan Khata (Farmer Credit Ledger):** Village-wise farmer account tracking with partial payment logs.
4. **Live Mandi Bhav Ticker:** Real-time crop rate updates for regional farmers.
