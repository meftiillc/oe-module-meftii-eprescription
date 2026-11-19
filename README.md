# MEFTii ePrescription Module for OpenEMR

This module integrates **MEFTii ePrescription & EPCS** directly into **OpenEMR** using SMART on FHIR.

---

## 🚀 Installation Instructions (For OpenEMR Administrators)

### Step 1: Install Module in OpenEMR
Clone the repository directly into your OpenEMR `custom_modules` directory:
```bash
cd /path/to/openemr/interface/modules/custom_modules/
git clone https://github.com/meftiillc/oe-module-meftii-eprescription.git
```
*(Or download and extract the release ZIP into `interface/modules/custom_modules/oe-module-meftii-eprescription`)*

### Step 2: Enable Module in OpenEMR
1. Log in to your OpenEMR instance as an Administrator.
2. Go to **Modules ➔ Manage Modules**.
3. Locate **MEFTii ePrescription Module** (`oe-module-meftii-eprescription`).
4. Click **[ Install ]** and then **[ Enable ]**.

### Step 3: Register API Client in OpenEMR
1. In OpenEMR, navigate to **Administration ➔ System ➔ API Clients**.
2. Click **Add Client**:
   - **Client Name**: `MEFTii ePrescription Portal`
   - **Client ID**: `mediscript_client`
   - **Redirect URI**: 
     - Local Dev: `http://localhost:5002/smart/callback`
     - Production: `https://eprescription.meftii.com/smart/callback`
   - **Scope**:
     ```text
     launch patient/Patient.read patient/AllergyIntolerance.read user/Practitioner.read user/Organization.read openid profile email fhirUser
     ```

---

## 👩‍⚕️ How Providers Use MEFTii in OpenEMR

1. Open any patient chart in OpenEMR.
2. In the patient dashboard or **Modules** menu, click **"E-Prescribe (MEFTii)"**.
3. **First-Time Setup**:
   - MEFTii detects your OpenEMR facility and provider details automatically.
   - Choose **Solo Practitioner** (1 Doctor) or **Group Practice / Clinic** (Multiple Doctors).
   - Confirm practice details and set your password.
4. **Subsequent Prescriptions**:
   - Clicking **"E-Prescribe"** opens the patient's prescription pad with demographics, allergies, and active medications pre-loaded.
