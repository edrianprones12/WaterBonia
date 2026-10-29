#pragma once
// Small owning adapter over the cJSON library bundled with the ESP32 core.
// Numeric access is strict: strings, fractions, negative and overflowing IDs fail closed.
#include <Arduino.h>
#include <cJSON.h>
#include <math.h>
#include <stdint.h>
#include <type_traits>
class JsonVariant {
    cJSON* node_;
    cJSON* parent_;
    const char* key_;
    void assign(cJSON* value) {
        if (!value) return;
        if (!parent_ || !cJSON_IsObject(parent_)) { cJSON_Delete(value); return; }
        cJSON_DeleteItemFromObjectCaseSensitive(parent_,key_);
        cJSON_AddItemToObject(parent_,key_,value);
    }
public:
    explicit JsonVariant(cJSON* node=nullptr,cJSON* parent=nullptr,const char* key=nullptr):node_(node),parent_(parent),key_(key){}
    cJSON* get() const {return parent_ ? cJSON_GetObjectItemCaseSensitive(parent_,key_) : node_;}
    JsonVariant operator[](const char* key) const {return JsonVariant(nullptr,get(),key);}
    bool isNull() const {return !get() || cJSON_IsNull(get());}
    JsonVariant& operator=(const JsonVariant& other) {assign(cJSON_Duplicate(other.get(),true));return *this;}
    JsonVariant& operator=(const char* value) {assign(cJSON_CreateString(value));return *this;}
    JsonVariant& operator=(const String& value) {return *this=value.c_str();}
    JsonVariant& operator=(bool value) {assign(cJSON_CreateBool(value));return *this;}
    JsonVariant& operator=(decltype(nullptr)) {assign(cJSON_CreateNull());return *this;}
    template<class T,typename std::enable_if<std::is_integral<T>::value && !std::is_same<T,bool>::value,int>::type=0>
    JsonVariant& operator=(T value) {assign(cJSON_CreateNumber((double)value));return *this;}
    uint32_t operator|(unsigned int fallback) const {
        cJSON* value=get();
        if(!cJSON_IsNumber(value) || !isfinite(value->valuedouble) || value->valuedouble<0 || value->valuedouble>UINT32_MAX || floor(value->valuedouble)!=value->valuedouble) return fallback;
        return (uint32_t)value->valuedouble;
    }
    const char* operator|(const char* fallback) const {cJSON* value=get();return cJSON_IsString(value)?value->valuestring:fallback;}
    bool operator==(bool expected) const {return cJSON_IsBool(get()) && (bool)cJSON_IsTrue(get())==expected;}
    bool operator!=(bool expected) const {return !(*this==expected);}
    bool operator==(int expected) const {return cJSON_IsNumber(get()) && get()->valuedouble==expected;}
    bool operator!=(int expected) const {return !(*this==expected);}
};
class JsonDocument {
public:
    cJSON* root;
    JsonDocument():root(cJSON_CreateObject()){}
    ~JsonDocument(){cJSON_Delete(root);}
    JsonDocument(const JsonDocument&)=delete;
    JsonDocument& operator=(const JsonDocument&)=delete;
    JsonVariant operator[](const char* key){return JsonVariant(nullptr,root,key);}
};
inline void serializeJson(JsonDocument& doc,String& out) {
    char* json=cJSON_PrintUnformatted(doc.root);out=json?json:"";cJSON_free(json);
}
inline bool deserializeJson(JsonDocument& doc,const String& json) {
    cJSON_Delete(doc.root);doc.root=nullptr;
    if(json.length()>8192) return true;
    doc.root=cJSON_Parse(json.c_str());return !cJSON_IsObject(doc.root);
}
